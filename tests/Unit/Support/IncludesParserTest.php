<?php

namespace Tests\Unit\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Support\IncludesParser;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class IncludesParserTest extends TestCase
{
    private function createParser(array $queryParams = []): IncludesParser
    {
        $request = Request::create('/test', 'GET', $queryParams);
        return new IncludesParser($request);
    }

    public function test_basic_include_parsing()
    {
        $parser = $this->createParser(['include' => 'users,permissions,tenant']);
        
        $this->assertTrue($parser->isIncluded('users'));
        $this->assertTrue($parser->isIncluded('permissions'));
        $this->assertTrue($parser->isIncluded('tenant'));
        $this->assertFalse($parser->isIncluded('addresses'));
    }

    public function test_basic_exclude_parsing()
    {
        $parser = $this->createParser([
            'include' => 'users,permissions,tenant,addresses',
            'exclude' => 'addresses,tenant'
        ]);
        
        $this->assertTrue($parser->isIncluded('users'));
        $this->assertTrue($parser->isIncluded('permissions'));
        $this->assertFalse($parser->isIncluded('tenant'));
        $this->assertFalse($parser->isIncluded('addresses'));
    }

    public function test_include_parameter_trimming()
    {
        $parser = $this->createParser(['include' => ' users , permissions , tenant ']);
        
        $this->assertTrue($parser->isIncluded('users'));
        $this->assertTrue($parser->isIncluded('permissions'));
        $this->assertTrue($parser->isIncluded('tenant'));
    }

    public function test_exclude_parameter_trimming()
    {
        $parser = $this->createParser([
            'include' => 'users,permissions,tenant',
            'exclude' => ' permissions , tenant '
        ]);
        
        $this->assertTrue($parser->isIncluded('users'));
        $this->assertFalse($parser->isIncluded('permissions'));
        $this->assertFalse($parser->isIncluded('tenant'));
    }

    public function test_default_includes()
    {
        $parser = $this->createParser();
        $parser->addDefaultInclude('tenant');
        $parser->addDefaultInclude('shortdata');
        
        $this->assertTrue($parser->isIncluded('tenant'));
        $this->assertTrue($parser->isIncluded('shortdata'));
        $this->assertFalse($parser->isIncluded('users'));
    }

    public function test_default_includes_with_query_params()
    {
        $parser = $this->createParser(['include' => 'users,permissions']);
        $parser->addDefaultInclude('tenant');
        $parser->addDefaultInclude('shortdata');
        
        $this->assertTrue($parser->isIncluded('users'));
        $this->assertTrue($parser->isIncluded('permissions'));
        $this->assertTrue($parser->isIncluded('tenant'));
        $this->assertTrue($parser->isIncluded('shortdata'));
    }

    public function test_remove_default_includes()
    {
        $parser = $this->createParser();
        $parser->addDefaultInclude('tenant');
        $parser->addDefaultInclude('shortdata');
        $parser->removeDefaultInclude('tenant');
        
        $this->assertFalse($parser->isIncluded('tenant'));
        $this->assertTrue($parser->isIncluded('shortdata'));
    }

    public function test_disabled_includes()
    {
        $parser = $this->createParser(['include' => 'users,permissions,sensitive_data']);
        $parser->addDisabledInclude('sensitive_data');
        
        $this->assertTrue($parser->isIncluded('users'));
        $this->assertTrue($parser->isIncluded('permissions'));
        $this->assertFalse($parser->isIncluded('sensitive_data'));
    }

    public function test_disabled_includes_override_defaults()
    {
        $parser = $this->createParser();
        $parser->addDefaultInclude('sensitive_data');
        $parser->addDisabledInclude('sensitive_data');
        
        $this->assertFalse($parser->isIncluded('sensitive_data'));
    }

    public function test_remove_disabled_includes()
    {
        $parser = $this->createParser(['include' => 'sensitive_data']);
        $parser->addDisabledInclude('sensitive_data');
        $parser->removeDisabledInclude('sensitive_data');
        
        $this->assertTrue($parser->isIncluded('sensitive_data'));
    }

    public function test_is_not_included()
    {
        $parser = $this->createParser(['include' => 'users,permissions']);
        
        $this->assertFalse($parser->isNotIncluded('users'));
        $this->assertFalse($parser->isNotIncluded('permissions'));
        $this->assertTrue($parser->isNotIncluded('tenant'));
    }

    public function test_get_includes()
    {
        $parser = $this->createParser(['include' => 'users,permissions']);
        $parser->addDefaultInclude('tenant');
        
        $includes = $parser->getIncludes();
        sort($includes);
        
        $this->assertEquals(['permissions', 'tenant', 'users'], $includes);
    }

    public function test_get_default_includes()
    {
        $parser = $this->createParser();
        $parser->addDefaultInclude('tenant');
        $parser->addDefaultInclude('shortdata');
        
        $defaults = $parser->getDefaultIncludes();
        sort($defaults);
        
        $this->assertEquals(['shortdata', 'tenant'], $defaults);
    }

    public function test_get_disabled_includes()
    {
        $parser = $this->createParser();
        $parser->addDisabledInclude('sensitive_data');
        $parser->addDisabledInclude('admin_only');
        
        $disabled = $parser->getDisabledIncludes();
        sort($disabled);
        
        $this->assertEquals(['admin_only', 'sensitive_data'], $disabled);
    }

    public function test_lazy_parsing()
    {
        $parser = $this->createParser(['include' => 'users']);
        
        // Parsing should not happen until first isIncluded call
        $this->assertTrue($parser->isIncluded('users'));
        
        // Subsequent calls should use cached result
        $this->assertFalse($parser->isIncluded('permissions'));
    }

    public function test_parse_state_reset_on_modification()
    {
        $parser = $this->createParser(['include' => 'users']);
        
        // Trigger initial parsing
        $this->assertTrue($parser->isIncluded('users'));
        
        // Modify parser state
        $parser->addDefaultInclude('tenant');
        
        // Should re-parse and include the new default
        $this->assertTrue($parser->isIncluded('tenant'));
    }

    public function test_empty_query_parameters()
    {
        $parser = $this->createParser(['include' => '', 'exclude' => '']);
        
        $this->assertFalse($parser->isIncluded('users'));
        $this->assertEquals([], $parser->getIncludes());
    }

    public function test_no_query_parameters()
    {
        $parser = $this->createParser();
        
        $this->assertFalse($parser->isIncluded('users'));
        $this->assertEquals([], $parser->getIncludes());
    }

    public function test_shortdata_convention()
    {
        $parser = $this->createParser(['include' => 'shortdata']);
        
        $this->assertTrue($parser->isIncluded('shortdata'));
        $this->assertFalse($parser->isIncluded('longdata'));
    }

    public function test_reset_functionality()
    {
        $parser = $this->createParser(['include' => 'users']);
        $parser->addDefaultInclude('tenant');
        $parser->addDisabledInclude('sensitive_data');
        
        // Verify initial state
        $this->assertTrue($parser->isIncluded('users'));
        $this->assertTrue($parser->isIncluded('tenant'));
        $this->assertEquals(['tenant'], $parser->getDefaultIncludes());
        $this->assertEquals(['sensitive_data'], $parser->getDisabledIncludes());
        
        // Reset and verify clean state
        $parser->reset();
        $this->assertTrue($parser->isIncluded('users')); // Still from query params
        $this->assertFalse($parser->isIncluded('tenant')); // Default removed
        $this->assertEquals([], $parser->getDefaultIncludes());
        $this->assertEquals([], $parser->getDisabledIncludes());
    }

    public function test_debug_logging()
    {
        Log::shouldReceive('debug')
            ->once()
            ->with('IncludesParser Debug State', \Mockery::type('array'));
        
        $parser = $this->createParser(['include' => 'users', 'exclude' => 'permissions']);
        $parser->addDefaultInclude('tenant');
        $parser->addDisabledInclude('sensitive_data');
        
        $parser->debug();
    }
}