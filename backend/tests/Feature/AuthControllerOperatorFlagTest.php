<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerOperatorFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_payload_cannot_set_is_platform_operator(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Attempted Operator',
            'email' => 'attempt@example.com',
            'password' => 'password123',
            'is_platform_operator' => true,
        ]);

        $response->assertCreated();

        $user = User::where('email', 'attempt@example.com')->firstOrFail();
        $this->assertFalse($user->is_platform_operator);
    }
}
