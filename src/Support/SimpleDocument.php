<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

use Illuminate\Support\Arr;
use Swis\JsonApi\Client\Document;
use Swis\JsonApi\Client\Interfaces\ItemDocumentInterface;

/**
 * Class SimpleDocument
 * We don't use JSON+API format for most request bodies.
 * This class overrides toArray to return a simple array
 * rather than a JSON+API formatted one.
 */
class SimpleDocument extends Document implements ItemDocumentInterface
{
    /**
     * @return object
     */
    public function jsonSerialize()
    {
        $document = [];

        if (! empty($this->getData())) {
            $jsonApiArray = $this->data->toJsonApiArray();
            $document = Arr::get($jsonApiArray, 'attributes', []);
        }

        return $document;
    }
}
