<?php

namespace Nuimarkets\LaravelSharedUtils\Support;

use Illuminate\Support\Arr;
use Swis\JsonApi\Client\Interfaces\ItemDocumentInterface;
use Swis\JsonApi\Client\Document;

/**
 * Class SimpleDocument
 * We don't use JSON+API format for most request bodies.
 * This class overrides toArray to return a simple array
 * rather than a JSON+API formatted one.
 *
 * @package Nuimarkets\LaravelSharedUtils\Support
 */
class SimpleDocument extends Document implements ItemDocumentInterface
{
    /**
     * @return object
     */
    public function jsonSerialize()
    {
        $document = [];

        if (!empty($this->getData())) {
            $jsonApiArray = $this->data->toJsonApiArray();
            $document = Arr::get($jsonApiArray, 'attributes', []);
        }

        return $document;
    }
}