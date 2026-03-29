<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Collection class for JSON:API formatted responses with pagination.
 *
 * Can be used directly or extended for custom behaviour:
 *
 *   // Direct usage - no subclass needed
 *   return new JsonApiCollection($paginator, TagResource::class);
 *
 *   // Subclass for custom logic (e.g. constructor enrichment)
 *   class ProductCollection extends JsonApiCollection { ... }
 *
 * Override makeRelativeUrl() if your service uses a different URL prefix.
 */
class JsonApiCollection extends ResourceCollection
{
    public function __construct($resource, ?string $collects = null)
    {
        if ($collects !== null) {
            $this->collects = $collects;
        }

        parent::__construct($resource);
    }
    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with($request): array
    {
        if (! $this->resource instanceof LengthAwarePaginator) {
            return [];
        }

        $currentPage = $this->resource->currentPage();
        $lastPage = $this->resource->lastPage();

        $links = [
            'self' => $this->makeRelativeUrl($this->resource->url($currentPage)),
            'first' => $this->makeRelativeUrl($this->resource->url(1)),
            'last' => $this->makeRelativeUrl($this->resource->url($lastPage)),
        ];

        if ($prev = $this->resource->previousPageUrl()) {
            $links['prev'] = $this->makeRelativeUrl($prev);
        }

        if ($next = $this->resource->nextPageUrl()) {
            $links['next'] = $this->makeRelativeUrl($next);
        }

        return [
            'meta' => [
                'pagination' => [
                    'total' => $this->resource->total(),
                    'count' => $this->resource->count(),
                    'per_page' => $this->resource->perPage(),
                    'current_page' => $currentPage,
                    'total_pages' => $lastPage,
                ],
            ],
            'links' => $links,
        ];
    }

    /**
     * Convert full URL to relative URL without /api prefix.
     *
     * Override in subclass if your service uses a different prefix.
     */
    protected function makeRelativeUrl(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $query = $parsed['query'] ?? '';

        $path = preg_replace('#^/api#', '', $path);

        return $path . ($query ? '?' . $query : '');
    }

    /**
     * Suppress Laravel's default pagination meta/links to avoid duplication.
     *
     * @return array<string, mixed>
     */
    public function paginationInformation($request, $paginated, $default): array
    {
        return [];
    }
}
