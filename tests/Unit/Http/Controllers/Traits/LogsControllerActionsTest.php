<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Http\Controllers\Traits;

use NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits\LogsControllerActions;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LogsControllerActionsTest extends TestCase
{
    protected TestController $controller;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new TestController();
        Log::spy();
    }
    
    public function test_log_action_start_with_basic_context()
    {
        $request = Request::create('/api/test', 'POST', ['name' => 'Test Item']);
        
        $this->controller->testLogActionStart('store', $request);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.store started',
            \Mockery::on(function ($context) {
                return $context['feature'] === 'test' &&
                       $context['action'] === 'store' &&
                       $context['request.method'] === 'POST' &&
                       $context['request.path'] === 'api/test';
            })
        );
    }
    
    public function test_log_action_start_with_authenticated_user()
    {
        $user = new \stdClass();
        $user->id = 123;
        $user->org_id = 456;
        
        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });
        
        $this->controller->testLogActionStart('index', $request);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.index started',
            \Mockery::on(function ($context) {
                return $context['request.user_id'] === 123 &&
                       $context['request.org_id'] === 456;
            })
        );
    }
    
    public function test_log_action_start_with_validated_data()
    {
        // Create a custom request class that implements validated method
        $request = new class extends Request {
            public function validated() {
                return ['email' => 'test@example.com', 'name' => 'Test'];
            }
        };
        $request->initialize([], [], [], [], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/users']);
        
        $this->controller->testLogActionStart('store', $request);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.store started',
            \Mockery::on(function ($context) {
                return isset($context['request_data']) &&
                       $context['request_data']['email'] === 'test@example.com' &&
                       $context['request_data']['name'] === 'Test';
            })
        );
    }
    
    public function test_log_action_start_with_additional_context()
    {
        $request = Request::create('/api/test', 'PUT');
        
        $this->controller->testLogActionStart('update', $request, [
            'entity_id' => 789,
            'version' => 2,
        ]);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.update started',
            \Mockery::on(function ($context) {
                return $context['entity_id'] === 789 &&
                       $context['version'] === 2;
            })
        );
    }
    
    public function test_log_action_success()
    {
        $request = Request::create('/api/test', 'POST');
        $result = ['id' => 123, 'name' => 'Created Item'];
        
        $this->controller->testLogActionSuccess('store', $result, $request);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.store completed successfully',
            \Mockery::on(function ($context) {
                return $context['result'] === 'success' &&
                       $context['result_data']['id'] === 123 &&
                       $context['result_data']['name'] === 'Created Item';
            })
        );
    }
    
    public function test_log_action_failure_with_exception()
    {
        $request = Request::create('/api/test', 'POST');
        $exception = new \RuntimeException('Database connection failed', 500);
        
        $this->controller->testLogActionFailure('store', $exception, $request);
        
        Log::shouldHaveReceived('error')->once()->with(
            'TestController.store failed',
            \Mockery::on(function ($context) {
                return $context['result'] === 'failure' &&
                       $context['exception'] === 'RuntimeException' &&
                       $context['error_message'] === 'Database connection failed' &&
                       $context['error_code'] === 500;
            })
        );
    }
    
    public function test_log_action_failure_with_string_error()
    {
        $request = Request::create('/api/test', 'DELETE');
        
        $this->controller->testLogActionFailure('destroy', 'Permission denied', $request);
        
        Log::shouldHaveReceived('error')->once()->with(
            'TestController.destroy failed',
            \Mockery::on(function ($context) {
                return $context['result'] === 'failure' &&
                       $context['error_message'] === 'Permission denied' &&
                       !isset($context['exception']);
            })
        );
    }
    
    public function test_does_not_log_sensitive_data()
    {
        $request = \Mockery::mock(Request::class);
        $request->shouldReceive('validated')->andReturn(['password' => 'secret123', 'email' => 'user@example.com']);
        $request->shouldReceive('method')->andReturn('POST');
        $request->shouldReceive('path')->andReturn('api/login');
        $request->shouldReceive('user')->andReturn(null);
        $request->shouldReceive('route')->andReturn(null);
        
        $this->controller->testLogActionStart('login', $request);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.login started',
            \Mockery::on(function ($context) {
                // Should not include validated data for sensitive actions
                return !isset($context['request_data']);
            })
        );
    }
    
    public function test_uses_custom_feature_name()
    {
        $controller = new CustomFeatureController();
        $request = Request::create('/api/custom', 'GET');
        
        $controller->testLogActionStart('index', $request);
        
        Log::shouldHaveReceived('info')->once()->with(
            'CustomFeatureController.index started',
            \Mockery::on(function ($context) {
                return $context['feature'] === 'custom_feature';
            })
        );
    }
    
    public function test_uses_appropriate_data_field_names()
    {
        // Test 'store' action uses 'request_data'
        $storeRequest = new class extends Request {
            public function validated() {
                return ['name' => 'New Item'];
            }
        };
        $storeRequest->initialize([], [], [], [], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/test']);
        
        $this->controller->testLogActionStart('store', $storeRequest);
        
        // Test 'update' action uses 'update_data'
        $updateRequest = new class extends Request {
            public function validated() {
                return ['name' => 'Updated Item'];
            }
        };
        $updateRequest->initialize([], [], [], [], [], ['REQUEST_METHOD' => 'PATCH', 'REQUEST_URI' => '/api/test']);
        
        $this->controller->testLogActionStart('update', $updateRequest);
        
        // Test 'index' action uses 'filters'
        $indexRequest = new class extends Request {
            public function validated() {
                return ['status' => 'active'];
            }
        };
        $indexRequest->initialize([], [], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/test']);
        
        $this->controller->testLogActionStart('index', $indexRequest);
        
        // Verify all three calls happened with the right context
        Log::shouldHaveReceived('info')->times(3);
        
        // Verify specific contexts
        Log::shouldHaveReceived('info')->with(
            \Mockery::any(),
            \Mockery::on(function ($context) {
                return isset($context['request_data']);
            })
        )->once();
        
        Log::shouldHaveReceived('info')->with(
            \Mockery::any(),
            \Mockery::on(function ($context) {
                return isset($context['update_data']);
            })
        )->once();
        
        Log::shouldHaveReceived('info')->with(
            \Mockery::any(),
            \Mockery::on(function ($context) {
                return isset($context['filters']);
            })
        )->once();
    }
    
    public function test_sanitizes_model_result_data()
    {
        $request = Request::create('/api/test', 'GET');
        
        // Create a simple object that implements toArray
        $model = new class {
            public function toArray() {
                return ['id' => 1, 'name' => 'Test Model'];
            }
        };
        
        $this->controller->testLogActionSuccess('show', $model, $request);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.show completed successfully',
            \Mockery::on(function ($context) {
                return isset($context['result_data']) &&
                       is_array($context['result_data']) &&
                       $context['result_data']['id'] === 1 &&
                       $context['result_data']['name'] === 'Test Model';
            })
        );
    }
    
    public function test_includes_route_parameters()
    {
        $request = Request::create('/api/orders/123/items/456', 'GET');
        $route = \Mockery::mock();
        $route->shouldReceive('parameters')->andReturn([
            'order_id' => '123',
            'item_id' => '456',
            'model_instance' => new \stdClass(), // Should be filtered out
        ]);
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });
        
        $this->controller->testLogActionStart('show', $request);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.show started',
            \Mockery::on(function ($context) {
                return isset($context['route_params']) &&
                       $context['route_params']['order_id'] === '123' &&
                       $context['route_params']['item_id'] === '456' &&
                       !isset($context['route_params']['model_instance']);
            })
        );
    }
    
    public function test_sanitize_result_data_handles_circular_references()
    {
        // Create objects with circular reference
        $objA = new \stdClass();
        $objB = new \stdClass();
        $objA->name = 'Object A';
        $objA->child = $objB;
        $objB->name = 'Object B';
        $objB->parent = $objA; // Circular reference
        
        $this->controller->testLogActionSuccess('test', $objA);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.test completed successfully',
            \Mockery::on(function ($context) {
                // Check that the circular reference was handled
                return isset($context['result_data']) &&
                       is_string($context['result_data']) &&
                       str_contains($context['result_data'], '[Object: stdClass]');
            })
        );
    }
    
    public function test_sanitize_result_data_handles_array_with_circular_references()
    {
        // Create a complex structure with circular references
        $parent = new \stdClass();
        $parent->id = 1;
        $child1 = new \stdClass();
        $child1->id = 2;
        $child2 = new \stdClass();
        $child2->id = 3;
        
        // Create circular references
        $parent->children = [$child1, $child2];
        $child1->parent = $parent;
        $child2->parent = $parent;
        $child1->sibling = $child2;
        $child2->sibling = $child1;
        
        // Test with an array containing the circular structure
        $result = [
            'parent' => $parent,
            'all_objects' => [$parent, $child1, $child2]
        ];
        
        $this->controller->testLogActionSuccess('test', $result);
        
        Log::shouldHaveReceived('info')->once()->with(
            'TestController.test completed successfully',
            \Mockery::on(function ($context) {
                // The result should be an array with circular references handled
                return isset($context['result_data']) &&
                       is_array($context['result_data']) &&
                       isset($context['result_data']['parent']) &&
                       str_contains($context['result_data']['parent'], '[Object: stdClass]');
            })
        );
    }
    
    public function test_extracts_keys_from_model_instances_in_route_parameters()
    {
        // Create a real model instance with getKey method
        $mockModel = new MockModel(999);
        
        $request = Request::create('/api/orders/123/items/456', 'GET');
        $route = \Mockery::mock();
        $route->shouldReceive('parameters')->andReturn([
            'order_id' => '123',
            'item_id' => '456',
            'order' => $mockModel, // Model instance with getKey method
            'plain_object' => new \stdClass(), // Should be filtered out
        ]);
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });
        
        $this->controller->testLogActionStart('show', $request);
        
        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context) {
            // Allow test to see what's actually being logged
            $this->assertEquals('TestController.show started', $message);
            $this->assertArrayHasKey('route_params', $context);
            
            $params = $context['route_params'];
            // Debug what's actually in the params
            $this->assertIsArray($params);
            
            // Check what keys are actually present
            $actualKeys = array_keys($params);
            $this->assertContains('order_id', $actualKeys);
            $this->assertContains('item_id', $actualKeys);
            
            // The issue might be that array_filter preserves keys but array_map doesn't
            // Let's check if order key exists
            if (isset($params['order'])) {
                $this->assertEquals(999, $params['order']);
            } else {
                $this->fail('Order key not found in params. Keys present: ' . implode(', ', $actualKeys));
            }
            
            $this->assertArrayNotHasKey('plain_object', $params);
            
            return true;
        });
    }
}

/**
 * Test controller implementation
 */
class TestController
{
    use LogsControllerActions;
    
    public function testLogActionStart($action, $request = null, $context = [])
    {
        $this->logActionStart($action, $request, $context);
    }
    
    public function testLogActionSuccess($action, $result = null, $request = null, $context = [])
    {
        $this->logActionSuccess($action, $result, $request, $context);
    }
    
    public function testLogActionFailure($action, $error, $request = null, $context = [])
    {
        $this->logActionFailure($action, $error, $request, $context);
    }
}

/**
 * Controller with custom feature name
 */
class CustomFeatureController
{
    use LogsControllerActions;
    
    protected $loggingFeatureName = 'custom_feature';
    
    public function testLogActionStart($action, $request = null, $context = [])
    {
        $this->logActionStart($action, $request, $context);
    }
}

/**
 * Mock model class with getKey method
 */
class MockModel
{
    protected $key;
    
    public function __construct($key)
    {
        $this->key = $key;
    }
    
    public function getKey()
    {
        return $this->key;
    }
}