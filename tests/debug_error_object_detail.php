<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NuiMarkets\LaravelSharedUtils\Support\ErrorCollectionParser;
use Swis\JsonApi\Client\Parsers\ErrorParser;
use Swis\JsonApi\Client\Parsers\LinksParser;
use Swis\JsonApi\Client\Parsers\MetaParser;

$metaParser = new MetaParser();
$linksParser = new LinksParser($metaParser);
$errorParser = new ErrorParser($linksParser, $metaParser);
$parser = new ErrorCollectionParser($errorParser);

$errorObjectFixture = [
    "error" => [
        "code" => 500,
        "message" => "Server error"
    ]
];

echo "Input: " . json_encode($errorObjectFixture, JSON_PRETTY_PRINT) . "\n";

try {
    $result = $parser->parse($errorObjectFixture);
    echo "Success! Got " . $result->count() . " errors\n";
    $error = $result->first();
    echo "Detail: '" . $error->getDetail() . "'\n";
    echo "Title: '" . $error->getTitle() . "'\n";
    echo "Code: '" . $error->getCode() . "' (type: " . gettype($error->getCode()) . ")\n";
    
    $meta = $error->getMeta();
    if ($meta) {
        echo "Meta: " . json_encode($meta->toArray(), JSON_PRETTY_PRINT) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}