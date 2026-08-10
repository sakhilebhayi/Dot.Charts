<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Found during an audit: every /api/* route without its own explicit
 * throttle:X (e.g. /me, /logout, the full /strategies and
 * /journal-entries CRUD surfaces) had zero rate limiting at all --
 * Laravel's minimal skeleton doesn't apply the classic default 'api'
 * throttle unless bootstrap/app.php opts in via throttleApi(). This
 * proves the backstop is real, not just configured and unused.
 */
class GlobalApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_endpoint_with_no_specific_throttle_is_still_capped_at_sixty_per_minute(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        for ($i = 0; $i < 60; $i++) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');
            $this->assertNotEquals(429, $response->status(), "Request {$i} unexpectedly rate limited");
        }

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->assertStatus(429);
    }

    public function test_the_global_limiter_is_keyed_by_ip_so_a_different_ip_is_unaffected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        for ($i = 0; $i < 61; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->getJson('/api/me');
        }
        $this->withHeader('Authorization', "Bearer {$token}")
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->getJson('/api/me')
            ->assertStatus(429);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->getJson('/api/me')
            ->assertOk();
    }
}
