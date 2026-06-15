<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\RemoteRepositoryTestHelpers;
use Swis\JsonApi\Client\Interfaces\ItemDocumentInterface;

/**
 * Guards the request-logging branch of RemoteRepository::post().
 *
 * The branch is only reached when remote_repository.log_requests is enabled,
 * so the rest of the suite (which runs with it off) never exercised it. That
 * gap let a removed json-api-client method survive a major-version bump: the
 * v1 client exposed encode(), v2 dropped it, and the branch kept calling it,
 * turning every logged POST into a fatal error. The regression is about the
 * client surface, so the document is a plain ItemDocumentInterface double:
 * post() must serialise the body via the document's own toArray() and never
 * reach for a method that the v2 client no longer has.
 */
class RemoteRepositoryRequestLoggingTest extends TestCase
{
    use RemoteRepositoryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteRepositoryConfig();
        config(['app.remote_repository.log_requests' => true]);
    }

    private function makeDocument(array $body = ['test' => 'data']): ItemDocumentInterface
    {
        $document = $this->createMock(ItemDocumentInterface::class);
        $document->method('toArray')->willReturn($body);

        return $document;
    }

    public function test_post_logs_request_body_via_document_not_a_removed_client_method()
    {
        $mockClient = $this->createMockClient();
        $mockClient->expects($this->once())
            ->method('post')
            ->willReturn($this->createSuccessResponse());

        // The client double only implements the v2 DocumentClientInterface
        // surface (get/post/patch/delete/get|setBaseUri). If post() reaches for
        // a client method that no longer exists (v1's encode()), the mock raises
        // and this test fails.
        $repository = $this->createTestRepositoryWithPublicMethods($mockClient);

        Log::spy();

        $repository->publicPost('/test', $this->makeDocument(['k' => 'v']));

        // The logged body is exactly what the document serialised itself to,
        // proving the branch went through $data->toArray() rather than the
        // removed client encode().
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Request Debug'
                    && ($context['body'] ?? null) === ['k' => 'v'];
            });
    }

    public function test_logging_branch_does_not_alter_the_outbound_request()
    {
        $captured = null;
        $mockClient = $this->createMockClient();
        $mockClient->expects($this->once())
            ->method('post')
            ->willReturnCallback(function ($url, $document, $headers) use (&$captured) {
                $captured = [$url, $document];

                return $this->createSuccessResponse();
            });

        $repository = $this->createTestRepositoryWithPublicMethods($mockClient);
        $document = $this->makeDocument();

        Log::spy();

        $repository->publicPost('/test', $document);

        // The original document instance is forwarded to the client untouched by
        // the log serialisation.
        $this->assertSame('/test', $captured[0]);
        $this->assertSame($document, $captured[1]);
    }
}
