<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use NuiMarkets\LaravelSharedUtils\Http\Resources\JsonApiCollection;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class StubItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'type' => 'items',
            'id' => (string) $this->resource['id'],
            'attributes' => ['name' => $this->resource['name']],
        ];
    }
}

class StubItemCollection extends JsonApiCollection
{
    public $collects = StubItemResource::class;
}

class JsonApiCollectionTest extends TestCase
{
    private function makeItems(int $count = 3): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['id' => $i, 'name' => "Item {$i}"];
        }

        return $items;
    }

    private function makePaginator(array $items, int $total, int $perPage, int $currentPage, string $path = '/api/items'): LengthAwarePaginator
    {
        $paginator = new LengthAwarePaginator($items, $total, $perPage, $currentPage);
        $paginator->setPath($path);

        return $paginator;
    }

    public function test_to_array_wraps_collection_in_data_key()
    {
        $items = $this->makeItems(2);
        $collection = new StubItemCollection(collect($items));
        $result = $collection->toArray(request());

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
    }

    public function test_with_returns_empty_for_non_paginated_collection()
    {
        $items = $this->makeItems(2);
        $collection = new StubItemCollection(collect($items));
        $result = $collection->with(request());

        $this->assertSame([], $result);
    }

    public function test_with_returns_pagination_meta_for_paginator()
    {
        $paginator = $this->makePaginator($this->makeItems(3), 10, 3, 1);
        $collection = new StubItemCollection($paginator);
        $result = $collection->with(request());

        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('pagination', $result['meta']);

        $pagination = $result['meta']['pagination'];
        $this->assertSame(10, $pagination['total']);
        $this->assertSame(3, $pagination['count']);
        $this->assertSame(3, $pagination['per_page']);
        $this->assertSame(1, $pagination['current_page']);
        $this->assertSame(4, $pagination['total_pages']);
    }

    public function test_with_returns_links_for_paginator()
    {
        $paginator = $this->makePaginator($this->makeItems(3), 10, 3, 2);
        $collection = new StubItemCollection($paginator);
        $result = $collection->with(request());

        $this->assertArrayHasKey('links', $result);

        $links = $result['links'];
        $this->assertArrayHasKey('self', $links);
        $this->assertArrayHasKey('first', $links);
        $this->assertArrayHasKey('last', $links);
        $this->assertArrayHasKey('prev', $links);
        $this->assertArrayHasKey('next', $links);
    }

    public function test_prev_link_omitted_on_first_page()
    {
        $paginator = $this->makePaginator($this->makeItems(3), 10, 3, 1);
        $collection = new StubItemCollection($paginator);
        $result = $collection->with(request());

        $this->assertArrayNotHasKey('prev', $result['links']);
        $this->assertArrayHasKey('next', $result['links']);
    }

    public function test_next_link_omitted_on_last_page()
    {
        $paginator = $this->makePaginator($this->makeItems(1), 10, 3, 4);
        $collection = new StubItemCollection($paginator);
        $result = $collection->with(request());

        $this->assertArrayHasKey('prev', $result['links']);
        $this->assertArrayNotHasKey('next', $result['links']);
    }

    public function test_links_are_relative_with_api_prefix_stripped()
    {
        $paginator = $this->makePaginator($this->makeItems(3), 10, 3, 2, '/api/items');
        $collection = new StubItemCollection($paginator);
        $result = $collection->with(request());

        $links = $result['links'];
        $this->assertSame('/items?page=2', $links['self']);
        $this->assertSame('/items?page=1', $links['first']);
        $this->assertSame('/items?page=4', $links['last']);
        $this->assertSame('/items?page=1', $links['prev']);
        $this->assertSame('/items?page=3', $links['next']);
    }

    public function test_links_work_without_api_prefix()
    {
        $paginator = $this->makePaginator($this->makeItems(3), 10, 3, 2, '/items');
        $collection = new StubItemCollection($paginator);
        $result = $collection->with(request());

        $links = $result['links'];
        $this->assertSame('/items?page=2', $links['self']);
    }

    public function test_prefix_strip_does_not_corrupt_similar_path_segments()
    {
        $paginator = $this->makePaginator($this->makeItems(3), 10, 3, 2, '/apiary/items');
        $collection = new StubItemCollection($paginator);
        $result = $collection->with(request());

        $links = $result['links'];
        $this->assertSame('/apiary/items?page=2', $links['self']);
    }

    public function test_pagination_information_returns_empty_to_suppress_laravel_defaults()
    {
        $collection = new StubItemCollection(collect([]));
        $result = $collection->paginationInformation(request(), [], ['links' => [], 'meta' => []]);

        $this->assertSame([], $result);
    }

    public function test_first_page_link_has_no_query_when_paginator_omits_it()
    {
        $paginator = $this->makePaginator($this->makeItems(3), 3, 3, 1, '/api/items');
        $collection = new StubItemCollection($paginator);
        $result = $collection->with(request());

        $links = $result['links'];
        // Single page - self and first should match, no prev/next
        $this->assertSame($links['self'], $links['first']);
        $this->assertSame($links['self'], $links['last']);
        $this->assertArrayNotHasKey('prev', $links);
        $this->assertArrayNotHasKey('next', $links);
    }

    public function test_direct_usage_with_collects_parameter()
    {
        $paginator = $this->makePaginator($this->makeItems(3), 10, 3, 1);
        $collection = new JsonApiCollection($paginator, StubItemResource::class);
        $result = $collection->toArray(request());

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
    }

    public function test_direct_usage_has_pagination()
    {
        $paginator = $this->makePaginator($this->makeItems(3), 10, 3, 2);
        $collection = new JsonApiCollection($paginator, StubItemResource::class);
        $result = $collection->with(request());

        $this->assertSame(10, $result['meta']['pagination']['total']);
        $this->assertArrayHasKey('prev', $result['links']);
        $this->assertArrayHasKey('next', $result['links']);
    }

    public function test_direct_usage_without_collects_still_works()
    {
        $items = $this->makeItems(2);
        $collection = new JsonApiCollection(collect($items));
        $result = $collection->toArray(request());

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
    }

    public function test_subclass_collects_not_overridden_by_null()
    {
        $paginator = $this->makePaginator($this->makeItems(2), 2, 10, 1);
        $collection = new StubItemCollection($paginator);
        $result = $collection->toArray(request());

        // StubItemCollection sets $collects = StubItemResource, verify resources are transformed
        $first = $result['data']->first();
        $this->assertSame('items', $first->resolve(request())['type']);
    }
}
