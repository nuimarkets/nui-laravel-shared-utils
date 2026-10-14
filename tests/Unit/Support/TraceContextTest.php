<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Support;

use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TraceContextTest extends TestCase
{
    private const XRAY_ID = '/^1-([0-9a-f]{8})-[0-9a-f]{24}$/';

    private const ROOT = '1-67a92466-4b6aa15a05ffcd4c510de968';

    private const PARENT = '53995c3f42cd8ad8';

    public function test_mint_produces_an_xray_trace_id_stamped_with_the_current_time()
    {
        $before = time();
        $traceId = TraceContext::mint();
        $after = time();

        $this->assertMatchesRegularExpression(self::XRAY_ID, $traceId);

        preg_match(self::XRAY_ID, $traceId, $matches);
        $this->assertGreaterThanOrEqual($before, hexdec($matches[1]));
        $this->assertLessThanOrEqual($after, hexdec($matches[1]));
    }

    public function test_mint_never_repeats()
    {
        $this->assertNotSame(TraceContext::mint(), TraceContext::mint());
    }

    public function test_nothing_is_set_until_a_trace_is_started_even_with_a_request_header()
    {
        // The context never reads the request itself: an entry starts it.
        $request = Request::create('/api/test', 'GET');
        $request->headers->set(TraceContext::HEADER, 'Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1');
        $this->app->instance('request', $request);

        $context = new TraceContext;

        $this->assertNull($context->current());
        $this->assertNull($context->header());
    }

    public function test_an_inbound_header_keeps_root_parent_and_sampled_and_drops_the_rest()
    {
        $context = new TraceContext;

        $context->startFromHeader('Self=1-67a92467-0123456789abcdef01234567;Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1;Lineage=a87bd80c:1|68fd508a:5');

        $this->assertSame(self::ROOT, $context->current());
        $this->assertSame(self::PARENT, $context->parent());
        $this->assertSame('1', $context->sampled());
        $this->assertSame('Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1', $context->header());
    }

    public function test_an_inbound_header_tolerates_spaces_around_its_fields()
    {
        $context = new TraceContext;

        $context->startFromHeader('Root='.self::ROOT.'; Parent='.self::PARENT.' ; Sampled=0');

        $this->assertSame('Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=0', $context->header());
    }

    public function test_the_first_root_in_an_inbound_header_wins()
    {
        $context = new TraceContext;

        $context->startFromHeader('Root='.self::ROOT.';Root=1-5f84c7a1-0123456789abcdef01234567');

        $this->assertSame(self::ROOT, $context->current());
    }

    public function test_an_inbound_header_with_only_a_root_sends_only_the_root()
    {
        $context = new TraceContext;

        $context->startFromHeader('Root='.self::ROOT);

        $this->assertSame('Root='.self::ROOT, $context->header());
    }

    #[DataProvider('headersWithNoValidRoot')]
    public function test_an_inbound_header_with_no_valid_root_starts_a_new_trace(?string $header)
    {
        $context = new TraceContext;

        $context->startFromHeader($header);

        $this->assertMatchesRegularExpression(self::XRAY_ID, $context->current());
        $this->assertNull($context->parent());
        $this->assertNull($context->sampled());
        $this->assertSame('Root='.$context->current(), $context->header());
    }

    public static function headersWithNoValidRoot(): array
    {
        return [
            'no header' => [null],
            'empty' => [''],
            'bare id, not an X-Ray header' => [self::ROOT],
            'malformed' => ['MalformedTraceId'],
            'root of the wrong shape, with a usable parent and flag' => ['Root=1-67a92466-4b6a;Parent='.self::PARENT.';Sampled=1'],
            'uppercase root' => ['Root='.strtoupper(self::ROOT)],
            'only a self field' => ['Self='.self::ROOT],
        ];
    }

    #[DataProvider('unusableParentsAndFlags')]
    public function test_an_unusable_parent_or_flag_is_dropped_and_the_root_kept(string $fields, string $expected)
    {
        $context = new TraceContext;

        $context->startFromHeader('Root='.self::ROOT.';'.$fields);

        $this->assertSame($expected, $context->header());
    }

    public static function unusableParentsAndFlags(): array
    {
        return [
            'sampling requested, not decided' => ['Parent='.self::PARENT.';Sampled=?', 'Root='.self::ROOT.';Parent='.self::PARENT],
            'flag out of range' => ['Sampled=2', 'Root='.self::ROOT],
            'parent too short' => ['Parent=53995c3f;Sampled=1', 'Root='.self::ROOT.';Sampled=1'],
            'parent not hex' => ['Parent=53995c3f42cd8adz', 'Root='.self::ROOT],
            'field with no value' => ['Parent;Sampled=1', 'Root='.self::ROOT.';Sampled=1'],
        ];
    }

    public function test_a_job_shaped_start_sends_root_and_sampled_without_a_parent()
    {
        $context = new TraceContext;

        $context->start(self::ROOT, null, '1');

        $this->assertSame('Root='.self::ROOT.';Sampled=1', $context->header());
    }

    public function test_starting_again_replaces_the_whole_trace()
    {
        $context = new TraceContext;
        $context->startFromHeader('Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1');

        $context->start('1-5f84c7a1-0123456789abcdef01234567');

        $this->assertSame('Root=1-5f84c7a1-0123456789abcdef01234567', $context->header());
    }

    public function test_start_with_no_root_mints_one()
    {
        $context = new TraceContext;

        $context->start();

        $this->assertMatchesRegularExpression(self::XRAY_ID, $context->current());
        $this->assertSame('Root='.$context->current(), $context->header());
    }

    public function test_an_invalid_root_drops_the_sampling_decision_that_came_with_it()
    {
        $context = new TraceContext;

        $context->start('not-a-root', null, '1');

        $this->assertNotSame('not-a-root', $context->current());
        $this->assertNull($context->sampled());
    }

    public function test_a_trailing_newline_makes_a_root_or_parent_invalid()
    {
        $context = new TraceContext;

        $context->start(self::ROOT, self::PARENT."\n", '1');
        $this->assertSame('Root='.self::ROOT.';Sampled=1', $context->header());

        $context->start(self::ROOT."\n", null, '1');
        $this->assertStringStartsNotWith(self::ROOT, $context->current());
        $this->assertNull($context->sampled());
    }
}
