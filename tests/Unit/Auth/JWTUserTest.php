<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Auth;

use NuiMarkets\LaravelSharedUtils\Auth\JWTUser;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class JWTUserTest extends TestCase
{
    public function test_creates_user_with_required_properties()
    {
        $user = new JWTUser(
            id: 'user-123',
            org_id: 'org-456',
            role: 'buyer'
        );

        $this->assertEquals('user-123', $user->id);
        $this->assertEquals('org-456', $user->org_id);
        $this->assertEquals('buyer', $user->role);
        $this->assertNull($user->email);
        $this->assertNull($user->org_name);
        $this->assertNull($user->org_type);
    }

    public function test_creates_user_with_all_properties()
    {
        $user = new JWTUser(
            id: 'user-123',
            org_id: 'org-456',
            role: 'seller',
            email: 'user@example.com',
            org_name: 'ABC Company',
            org_type: 'supplier'
        );

        $this->assertEquals('user-123', $user->id);
        $this->assertEquals('org-456', $user->org_id);
        $this->assertEquals('seller', $user->role);
        $this->assertEquals('user@example.com', $user->email);
        $this->assertEquals('ABC Company', $user->org_name);
        $this->assertEquals('supplier', $user->org_type);
    }

    public function test_properties_are_readonly()
    {
        $user = new JWTUser(
            id: 'user-123',
            org_id: 'org-456',
            role: 'admin'
        );

        // PHP should prevent modifying readonly properties
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $user->id = 'new-id';
    }

    public function test_supports_different_roles()
    {
        $roles = ['buyer', 'seller', 'admin', 'machine', 'owner'];

        foreach ($roles as $role) {
            $user = new JWTUser(
                id: 'user-123',
                org_id: 'org-456',
                role: $role
            );

            $this->assertEquals($role, $user->role);
        }
    }

    public function test_handles_null_optional_properties()
    {
        $user = new JWTUser(
            id: 'user-123',
            org_id: 'org-456',
            role: 'buyer',
            email: null,
            org_name: null,
            org_type: null
        );

        $this->assertNull($user->email);
        $this->assertNull($user->org_name);
        $this->assertNull($user->org_type);
    }
}
