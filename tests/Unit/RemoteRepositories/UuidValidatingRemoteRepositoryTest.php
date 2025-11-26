<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\UuidValidatingRemoteRepository;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\RemoteRepositoryTestHelpers;
use Swis\JsonApi\Client\Interfaces\DocumentClientInterface;

class UuidValidatingRemoteRepositoryTest extends TestCase
{
    use RemoteRepositoryTestHelpers;

    protected $mockClient;

    protected $mockTokenService;

    protected $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteRepositoryConfig();

        // Mock dependencies using Mockery (specific to this test class)
        $this->mockClient = Mockery::mock(DocumentClientInterface::class);
        $this->mockTokenService = Mockery::mock(MachineTokenServiceInterface::class);

        // Mock client methods
        $this->mockClient->shouldReceive('setBaseUri')->andReturnSelf();

        // Mock token service methods
        $this->mockTokenService->shouldReceive('getToken')->andReturn('mock-token');

        // Create a concrete implementation for testing
        $this->repository = new class($this->mockClient, $this->mockTokenService) extends UuidValidatingRemoteRepository
        {
            protected function getConfiguredBaseUri(): string
            {
                return 'https://test-api.com';
            }

            protected function filter(array $data)
            {
                // Mock implementation - not used in our tests
                return null;
            }

            // Make protected method public for testing
            public function test_filter_valid_uuids(array $uuids): array
            {
                return $this->filterValidUuids($uuids);
            }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_filter_valid_uuids_with_all_valid_uuids()
    {
        $validUuids = [
            '550e8400-e29b-41d4-a716-446655440000',
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            '6ba7b811-9dad-11d1-80b4-00c04fd430c8',
        ];

        Log::shouldReceive('warning')->never();

        $result = $this->repository->test_filter_valid_uuids($validUuids);

        $this->assertEquals($validUuids, $result);
        $this->assertCount(3, $result);
    }

    public function test_filter_valid_uuids_with_empty_array()
    {
        Log::shouldReceive('warning')->never();

        $result = $this->repository->test_filter_valid_uuids([]);

        $this->assertEmpty($result);
        $this->assertCount(0, $result);
    }

    public function test_filter_valid_uuids_with_some_invalid_uuids()
    {
        $mixedUuids = [
            '550e8400-e29b-41d4-a716-446655440000', // valid
            'not-a-uuid',                             // invalid
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8', // valid
            123,                                      // invalid
            '',                                       // invalid
            null,                                     // invalid
            '6ba7b811-9dad-11d1-80b4-00c04fd430c8',  // valid
        ];

        $expectedValid = [
            '550e8400-e29b-41d4-a716-446655440000',
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            '6ba7b811-9dad-11d1-80b4-00c04fd430c8',
        ];

        // Expect warning log to be called with basic validation
        Log::shouldReceive('warning')
            ->once()
            ->with('Invalid UUIDs filtered from RemoteRepository query', Mockery::on(function ($logData) {
                return $logData['invalid_count'] === 4 &&
                       $logData['total_count'] === 7 &&
                       $logData['valid_count'] === 3 &&
                       in_array('not-a-uuid', $logData['invalid_uuids']) &&
                       in_array(123, $logData['invalid_uuids']);
            }));

        $result = $this->repository->test_filter_valid_uuids($mixedUuids);

        $this->assertEquals($expectedValid, array_values($result));
        $this->assertCount(3, $result);
    }

    public function test_filter_valid_uuids_with_all_invalid_uuids()
    {
        $invalidUuids = [
            'not-a-uuid',
            123,
            '',
            null,
            'invalid-uuid-format',
        ];

        // Expect warning log to be called
        Log::shouldReceive('warning')
            ->once()
            ->with('Invalid UUIDs filtered from RemoteRepository query', Mockery::on(function ($logData) {
                return $logData['invalid_count'] === 5 &&
                       $logData['total_count'] === 5 &&
                       $logData['valid_count'] === 0;
            }));

        $result = $this->repository->test_filter_valid_uuids($invalidUuids);

        $this->assertEmpty($result);
        $this->assertCount(0, $result);
    }

    public function test_find_by_ids_calls_filter_valid_uuids()
    {
        $mixedIds = [
            '550e8400-e29b-41d4-a716-446655440000', // valid
            'invalid-id',                             // invalid
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8',  // valid
        ];

        // Expect warning log to be called for the invalid ID
        Log::shouldReceive('warning')->once();

        // Mock the data collection with keyed items for parent::findByIds()
        $mockCollection = new Collection([
            '550e8400-e29b-41d4-a716-446655440000' => (object) ['id' => '550e8400-e29b-41d4-a716-446655440000'],
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8' => (object) ['id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8'],
        ]);

        // Use reflection to set the data property
        $reflection = new \ReflectionClass($this->repository);
        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setAccessible(true);
        $dataProperty->setValue($this->repository, $mockCollection);

        $result = $this->repository->findByIds($mixedIds);

        // Should return collection with only valid UUIDs
        $this->assertInstanceOf(Collection::class, $result);
        // The collection should contain the 2 valid UUIDs that exist in our mock data
        $this->assertCount(2, $result);
    }

    public function test_uuid_validation_regex_patterns()
    {
        $testCases = [
            // Valid UUIDs
            ['550e8400-e29b-41d4-a716-446655440000', true],
            ['6ba7b810-9dad-11d1-80b4-00c04fd430c8', true],
            ['00000000-0000-0000-0000-000000000000', true],
            ['FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF', true],
            ['a1b2c3d4-e5f6-7890-abcd-ef1234567890', true],

            // Invalid UUIDs
            ['not-a-uuid', false],
            ['550e8400-e29b-41d4-a716', false], // too short
            ['550e8400-e29b-41d4-a716-446655440000-extra', false], // too long
            ['550e8400_e29b_41d4_a716_446655440000', false], // wrong separators
            ['', false],
            [null, false],
            [123, false],
            ['550g8400-e29b-41d4-a716-446655440000', false], // invalid character 'g'
        ];

        Log::shouldReceive('warning')->times(8); // For the 8 invalid cases

        foreach ($testCases as [$uuid, $shouldBeValid]) {
            $result = $this->repository->test_filter_valid_uuids([$uuid]);

            if ($shouldBeValid) {
                $this->assertCount(1, $result, "UUID should be valid: {$uuid}");
                $this->assertEquals([$uuid], $result, "Valid UUID should be preserved: {$uuid}");
            } else {
                $this->assertCount(0, $result, 'UUID should be invalid: '.var_export($uuid, true));
            }
        }
    }
}
