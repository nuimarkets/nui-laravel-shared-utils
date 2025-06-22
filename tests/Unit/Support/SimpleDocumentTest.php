<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Support;

use NuiMarkets\LaravelSharedUtils\Support\SimpleDocument;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Swis\JsonApi\Client\Interfaces\ItemInterface;
use Swis\JsonApi\Client\Item;

class SimpleDocumentTest extends TestCase
{
    private SimpleDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->document = new SimpleDocument;
    }

    public function test_simple_document_can_be_instantiated()
    {
        $this->assertInstanceOf(SimpleDocument::class, $this->document);
    }

    public function test_can_set_and_get_data()
    {
        $item = new Item(['name' => 'Test Product', 'price' => 100]);
        $item->setType('product');

        $this->document->setData($item);

        $result = $this->document->getData();

        $this->assertInstanceOf(ItemInterface::class, $result);
        $this->assertEquals('product', $result->getType());
        $this->assertEquals('Test Product', $result->getAttribute('name'));
        $this->assertEquals(100, $result->getAttribute('price'));
    }

    public function test_can_handle_array_data()
    {
        $arrayData = ['ids' => [1, 2, 3], 'filters' => ['active' => true]];

        Item::unguard();
        $item = new Item($arrayData);
        Item::reguard();
        $item->setType('array');

        $this->document->setData($item);

        $result = $this->document->getData();

        $this->assertEquals('array', $result->getType());
        $this->assertEquals([1, 2, 3], $result->getAttribute('ids'));
        $this->assertEquals(['active' => true], $result->getAttribute('filters'));
    }

    public function test_handles_empty_data_gracefully()
    {
        // Test with empty item instead of null since JSON API client doesn't allow null
        $emptyItem = new Item([]);
        $emptyItem->setType('empty');

        $this->document->setData($emptyItem);

        $result = $this->document->getData();

        $this->assertInstanceOf(ItemInterface::class, $result);
        $this->assertEquals('empty', $result->getType());
        $this->assertEmpty($result->getAttributes());
    }

    public function test_implements_required_interfaces()
    {
        $this->assertInstanceOf(\Swis\JsonApi\Client\Interfaces\ItemDocumentInterface::class, $this->document);
        $this->assertInstanceOf(\Swis\JsonApi\Client\Interfaces\DocumentInterface::class, $this->document);
    }

    public function test_can_be_used_with_remote_repository_make_request_body()
    {
        $testData = [
            'product_ids' => [1, 2, 3],
            'filters' => [
                'category' => 'electronics',
                'price_min' => 50,
            ],
        ];

        Item::unguard();
        $item = new Item($testData);
        Item::reguard();
        $item->setType('array');

        $document = new SimpleDocument;
        $document->setData($item);

        $this->assertInstanceOf(SimpleDocument::class, $document);
        $this->assertEquals('array', $document->getData()->getType());
        $this->assertEquals([1, 2, 3], $document->getData()->getAttribute('product_ids'));
        $this->assertEquals('electronics', $document->getData()->getAttribute('filters')['category']);
    }

    public function test_simple_document_preserves_complex_data_structures()
    {
        $complexData = [
            'nested' => [
                'level1' => [
                    'level2' => ['value' => 'deep'],
                ],
            ],
            'array_of_objects' => [
                ['id' => 1, 'name' => 'First'],
                ['id' => 2, 'name' => 'Second'],
            ],
        ];

        Item::unguard();
        $item = new Item($complexData);
        Item::reguard();
        $item->setType('complex');

        $this->document->setData($item);

        $result = $this->document->getData();

        $this->assertEquals('complex', $result->getType());
        $this->assertEquals('deep', $result->getAttribute('nested')['level1']['level2']['value']);
        $this->assertCount(2, $result->getAttribute('array_of_objects'));
        $this->assertEquals('First', $result->getAttribute('array_of_objects')[0]['name']);
    }
}
