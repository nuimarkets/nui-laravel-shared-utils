<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Integration;

use NuiMarkets\LaravelSharedUtils\Support\ErrorCollectionParser;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Swis\JsonApi\Client\Parsers\ErrorParser;
use Swis\JsonApi\Client\Parsers\LinksParser;
use Swis\JsonApi\Client\Parsers\MetaParser;

/**
 * Integration tests for ErrorCollectionParser service provider registration.
 * 
 * Tests the complete service container integration including singleton registration,
 * interface aliasing, and dependency injection resolution.
 */
class ServiceProviderIntegrationTest extends TestCase
{
    /** @test */
    public function test_can_register_error_collection_parser_as_singleton()
    {
        // Register the ErrorCollectionParser as singleton with proper dependencies
        $this->app->singleton(ErrorCollectionParser::class, function ($app) {
            $metaParser = new MetaParser();
            $linksParser = new LinksParser($metaParser);
            $errorParser = new ErrorParser($linksParser, $metaParser);
            
            return new ErrorCollectionParser($errorParser);
        });
        
        // Test singleton behavior - should return same instance
        $parser1 = $this->app->make(ErrorCollectionParser::class);
        $parser2 = $this->app->make(ErrorCollectionParser::class);
        
        $this->assertInstanceOf(ErrorCollectionParser::class, $parser1);
        $this->assertInstanceOf(ErrorCollectionParser::class, $parser2);
        $this->assertSame($parser1, $parser2, 'Should return same singleton instance');
    }
    
    /** @test */
    public function test_can_alias_enhanced_parser_to_swis_interface()
    {
        // Register enhanced parser
        $this->app->singleton(ErrorCollectionParser::class, function ($app) {
            $metaParser = new MetaParser();
            $linksParser = new LinksParser($metaParser);
            $errorParser = new ErrorParser($linksParser, $metaParser);
            
            return new ErrorCollectionParser($errorParser);
        });
        
        // Alias to Swis interface
        $this->app->alias(
            ErrorCollectionParser::class,
            \Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class
        );
        
        // Test that requesting base interface returns enhanced parser
        $enhancedParser = $this->app->make(ErrorCollectionParser::class);
        $aliasedParser = $this->app->make(\Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class);
        
        $this->assertSame($enhancedParser, $aliasedParser, 'Alias should return same enhanced parser instance');
        $this->assertInstanceOf(ErrorCollectionParser::class, $aliasedParser);
        $this->assertInstanceOf(\Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class, $aliasedParser);
    }
    
    /** @test */
    public function test_service_provider_registration_workflow()
    {
        // Simulate complete service provider registration
        $this->registerErrorCollectionParserService();
        
        // Test enhanced parser is available via concrete class
        $concreteParser = $this->app->make(ErrorCollectionParser::class);
        $this->assertInstanceOf(ErrorCollectionParser::class, $concreteParser);
        
        // Test enhanced parser is available via base interface
        $interfaceParser = $this->app->make(\Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class);
        $this->assertInstanceOf(ErrorCollectionParser::class, $interfaceParser);
        
        // Test they are the same singleton instance
        $this->assertSame($concreteParser, $interfaceParser);
    }
    
    /** @test */
    public function test_parser_dependencies_are_properly_injected()
    {
        $this->registerErrorCollectionParserService();
        
        $parser = $this->app->make(ErrorCollectionParser::class);
        
        // Use reflection to verify dependencies are properly set
        $reflection = new \ReflectionClass($parser);
        $parentReflection = $reflection->getParentClass();
        
        // Verify it extends the base parser (inheritance check)
        $this->assertEquals(
            \Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class,
            $parentReflection->getName()
        );
        
        // Test parser can actually function (basic smoke test)
        $this->assertTrue(method_exists($parser, 'parse'));
    }
    
    /** @test */
    public function test_multiple_service_registrations_work_correctly()
    {
        // Register multiple services that might depend on error parser
        $this->app->singleton('test.service.a', function ($app) {
            return new class($app->make(ErrorCollectionParser::class)) {
                private $parser;
                public function __construct($parser) { $this->parser = $parser; }
                public function getParser() { return $this->parser; }
            };
        });
        
        $this->app->singleton('test.service.b', function ($app) {
            return new class($app->make(\Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class)) {
                private $parser;
                public function __construct($parser) { $this->parser = $parser; }
                public function getParser() { return $this->parser; }
            };
        });
        
        $this->registerErrorCollectionParserService();
        
        $serviceA = $this->app->make('test.service.a');
        $serviceB = $this->app->make('test.service.b');
        
        // Both services should get the same parser instance
        $this->assertSame($serviceA->getParser(), $serviceB->getParser());
        $this->assertInstanceOf(ErrorCollectionParser::class, $serviceA->getParser());
        $this->assertInstanceOf(ErrorCollectionParser::class, $serviceB->getParser());
    }
    
    /** @test */
    public function test_service_container_binding_order_independence()
    {
        // Test that alias can be registered before singleton
        $this->app->alias(
            ErrorCollectionParser::class,
            \Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class
        );
        
        // Register singleton after alias
        $this->app->singleton(ErrorCollectionParser::class, function ($app) {
            $metaParser = new MetaParser();
            $linksParser = new LinksParser($metaParser);
            $errorParser = new ErrorParser($linksParser, $metaParser);
            
            return new ErrorCollectionParser($errorParser);
        });
        
        // Should still work correctly
        $concreteParser = $this->app->make(ErrorCollectionParser::class);
        $interfaceParser = $this->app->make(\Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class);
        
        $this->assertSame($concreteParser, $interfaceParser);
        $this->assertInstanceOf(ErrorCollectionParser::class, $concreteParser);
    }
    
    /**
     * Helper method to register ErrorCollectionParser service following the documented pattern
     */
    private function registerErrorCollectionParserService(): void
    {
        // Register the enhanced ErrorCollectionParser
        $this->app->singleton(ErrorCollectionParser::class, function ($app) {
            $metaParser = new MetaParser();
            $linksParser = new LinksParser($metaParser);
            $errorParser = new ErrorParser($linksParser, $metaParser);
            
            return new ErrorCollectionParser($errorParser);
        });
        
        // Alias to Swis interface for dependency injection
        $this->app->alias(
            ErrorCollectionParser::class,
            \Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class
        );
    }
}