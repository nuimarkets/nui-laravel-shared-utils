<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Logging;

use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class LogFieldsTest extends TestCase
{
    public function test_get_all_fields_returns_all_constants()
    {
        $fields = TestLogFields::getAllFields();

        $this->assertIsArray($fields);
        $this->assertArrayHasKey('TARGET', $fields);
        $this->assertArrayHasKey('USER_ID', $fields);
        $this->assertArrayHasKey('REQUEST_ID', $fields);
        $this->assertEquals('target', $fields['TARGET']);
    }

    public function test_get_fields_by_category_returns_grouped_fields()
    {
        $categories = TestLogFields::getFieldsByCategory();

        $this->assertIsArray($categories);
        $this->assertArrayHasKey('core', $categories);
        $this->assertArrayHasKey('request', $categories);
        $this->assertArrayHasKey('user', $categories);
        $this->assertArrayHasKey('error', $categories);

        // Check specific category contents
        $this->assertArrayHasKey('TARGET', $categories['core']);
        $this->assertArrayHasKey('REQUEST_ID', $categories['request']);
        $this->assertArrayHasKey('USER_ID', $categories['user']);
    }

    public function test_is_valid_field_validates_field_names()
    {
        // Test valid fields
        $this->assertTrue(TestLogFields::isValidField('target'));
        $this->assertTrue(TestLogFields::isValidField('user_id'));
        $this->assertTrue(TestLogFields::isValidField('request_id'));

        // Test invalid fields
        $this->assertFalse(TestLogFields::isValidField('invalid_field'));
        $this->assertFalse(TestLogFields::isValidField(''));
        $this->assertFalse(TestLogFields::isValidField('TARGET')); // Should check values, not keys
    }

    public function test_service_specific_fields_can_be_extended()
    {
        $serviceFields = ExtendedLogFields::getServiceSpecificFields();

        $this->assertIsArray($serviceFields);
        $this->assertArrayHasKey('ORDER_ID', $serviceFields);
        $this->assertArrayHasKey('ORDER_STATUS', $serviceFields);
        $this->assertEquals('order_id', $serviceFields['ORDER_ID']);
        $this->assertEquals('order_status', $serviceFields['ORDER_STATUS']);
    }

    public function test_extended_fields_are_included_in_all_fields()
    {
        $allFields = ExtendedLogFields::getAllFields();

        // Should include both base and extended fields
        $this->assertArrayHasKey('TARGET', $allFields);
        $this->assertArrayHasKey('ORDER_ID', $allFields);
        $this->assertArrayHasKey('ORDER_STATUS', $allFields);
    }

    public function test_field_constants_follow_naming_convention()
    {
        $fields = TestLogFields::getAllFields();

        foreach ($fields as $key => $value) {
            // Keys should be UPPER_SNAKE_CASE
            $this->assertEquals(strtoupper($key), $key, "Field key '{$key}' should be uppercase");
            $this->assertMatchesRegularExpression('/^[A-Z_]+$/', $key, "Field key '{$key}' should only contain uppercase letters and underscores");

            // Values should be lower_snake_case or dot.notation
            $this->assertMatchesRegularExpression('/^[a-z._]+$/', $value, "Field value '{$value}' should be lowercase with underscores or dots");
        }
    }
}

/**
 * Concrete implementation for testing
 */
class TestLogFields extends LogFields
{
    // Uses all the base fields
}

/**
 * Extended implementation simulating a service-specific LogFields
 */
class ExtendedLogFields extends LogFields
{
    const ORDER_ID = 'order_id';

    const ORDER_STATUS = 'order_status';

    public static function getServiceSpecificFields(): array
    {
        return [
            'ORDER_ID' => self::ORDER_ID,
            'ORDER_STATUS' => self::ORDER_STATUS,
        ];
    }
}
