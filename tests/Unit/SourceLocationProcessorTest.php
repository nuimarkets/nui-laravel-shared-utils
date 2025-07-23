<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit;

use NuiMarkets\LaravelSharedUtils\Logging\SourceLocationProcessor;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class SourceLocationProcessorTest extends TestCase
{
    public function test_limits_frame_fields_to_prevent_field_explosion()
    {
        // Create processor with custom limits
        $processor = new SourceLocationProcessor(maxFrames: 5, outputFrames: 2);
        
        $record = [
            'message' => 'Test log message',
            'level' => 200,
            'extra' => []
        ];
        
        $result = $processor($record);
        
        // Should have debug_trace
        $this->assertArrayHasKey('debug_trace', $result['extra']);
        $this->assertStringContainsString('Trace count:', $result['extra']['debug_trace']);
        
        // Should only have limited frame fields
        $frameKeys = array_filter(array_keys($result['extra']), fn($key) => str_starts_with($key, 'frame_'));
        $this->assertLessThanOrEqual(2, count($frameKeys), 'Should not exceed outputFrames limit');
        
        // Check frame fields are properly numbered
        $this->assertArrayHasKey('frame_0', $result['extra']);
        if (count($frameKeys) > 1) {
            $this->assertArrayHasKey('frame_1', $result['extra']);
        }
        
        // Should not have frame_2 or higher (limited to 2)
        $this->assertArrayNotHasKey('frame_2', $result['extra']);
        $this->assertArrayNotHasKey('frame_3', $result['extra']);
    }
    
    public function test_default_constructor_limits_frames()
    {
        $processor = new SourceLocationProcessor();
        
        $record = [
            'message' => 'Test log message',
            'level' => 200,
            'extra' => []
        ];
        
        $result = $processor($record);
        
        // Should have exactly 3 frame fields by default
        $frameKeys = array_filter(array_keys($result['extra']), fn($key) => str_starts_with($key, 'frame_'));
        $this->assertLessThanOrEqual(3, count($frameKeys), 'Default should limit to 3 frames');
        
        // Should not create excessive frame fields
        $this->assertArrayNotHasKey('frame_10', $result['extra']);
        $this->assertArrayNotHasKey('frame_43', $result['extra']);
    }
    
    public function test_adds_source_file_and_line()
    {
        $processor = new SourceLocationProcessor();
        
        $record = [
            'message' => 'Test log message',
            'level' => 200,
            'extra' => []
        ];
        
        $result = $processor($record);
        
        // Should add source location (this test file)
        $this->assertArrayHasKey('source_file', $result['extra']);
        $this->assertArrayHasKey('source_line', $result['extra']);
        $this->assertStringContainsString('SourceLocationProcessorTest.php', $result['extra']['source_file']);
        $this->assertIsInt($result['extra']['source_line']);
    }
    
    public function test_constructor_validates_parameters()
    {
        // Test minimum values are enforced
        $processor = new SourceLocationProcessor(maxFrames: 0, outputFrames: 0);
        
        $record = [
            'message' => 'Test log message',
            'level' => 200,
            'extra' => []
        ];
        
        $result = $processor($record);
        
        // Should have at least 1 frame field even with invalid constructor params
        $frameKeys = array_filter(array_keys($result['extra']), fn($key) => str_starts_with($key, 'frame_'));
        $this->assertGreaterThanOrEqual(1, count($frameKeys), 'Should enforce minimum of 1 frame');
    }
    
    public function test_output_frames_cannot_exceed_max_frames()
    {
        // Try to set outputFrames higher than maxFrames
        $processor = new SourceLocationProcessor(maxFrames: 2, outputFrames: 5);
        
        $record = [
            'message' => 'Test log message',
            'level' => 200,
            'extra' => []
        ];
        
        $result = $processor($record);
        
        // Should be capped at maxFrames
        $frameKeys = array_filter(array_keys($result['extra']), fn($key) => str_starts_with($key, 'frame_'));
        $this->assertLessThanOrEqual(2, count($frameKeys), 'outputFrames should be capped at maxFrames');
    }
}