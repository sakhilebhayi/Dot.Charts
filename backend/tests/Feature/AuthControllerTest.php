<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ]);

        $user = User::where('email', 'ada@example.com')->first();
        $this->assertNotSame('correct-horse-battery-staple', $user->password, 'password must be hashed');
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_requires_all_fields(): void
    {
        $response = $this->postJson('/api/register', ['email' => 'ada@example.com']);

        $response->assertStatus(422);
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => bcrypt('correct-horse-battery-staple'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_login_rejects_wrong_password_with_401(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => bcrypt('correct-horse-battery-staple'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
    }

    public function test_login_rejects_unknown_email_with_401(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever-it-is',
        ]);

        $response->assertStatus(401);
    }

    public function test_me_returns_current_user_when_authenticated(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('email', 'ada@example.com');
    }

    public function test_me_returns_401_when_not_authenticated(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_logout_invalidates_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout');
        $logoutResponse->assertOk();

        // The 'sanctum' guard caches its resolved user for the lifetime of
        // the AuthManager instance — a real request-per-process app never
        // hits this, but two simulated requests in the same test method
        // share that instance, so the guard must be reset to force it to
        // re-resolve (and find the token gone) on the second call.
        $this->app['auth']->forgetGuards();

        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');
        $meResponse->assertStatus(401);
    }
}
