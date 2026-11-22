<?php

namespace Tests\Feature\Sso;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Local registration and password login: open while the flag is off, closed when it is on, break-glass reopens. */
class LocalAuthTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email = 'new@example.test')
    {
        return $this->postJson('/api/register', ['name' => 'N', 'email' => $email, 'password' => 'password-123']);
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        config(['dotsso.enabled' => false]);

        $this->register()->assertCreated();
        $this->postJson('/api/login', ['email' => 'new@example.test', 'password' => 'password-123'])->assertOk();
        $this->getJson('/api/auth/config')->assertOk()->assertExactJson(['sso' => false, 'local_login' => true, 'login_url' => null]);
        $this->get('/sso/login')->assertNotFound();
        $this->get('/sso/callback')->assertNotFound();
        $this->post('/sso/logout')->assertNotFound();
        $this->postJson('/api/auth/sso-logout')->assertOk()->assertJsonPath('redirect', url('/'));
    }

    public function test_with_the_flag_on_registration_and_password_login_are_closed(): void
    {
        config(['dotsso.enabled' => true, 'dotsso.allow_local_login' => false]);
        User::factory()->create(['email' => 'old@example.test', 'password' => 'password-123']);

        $this->register()->assertForbidden()->assertJsonPath('sso_url', url('/sso/login'));
        $this->postJson('/api/login', ['email' => 'old@example.test', 'password' => 'password-123'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::count());
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('sso', true)->assertJsonPath('local_login', false)->assertJsonPath('login_url', url('/sso/login'));
    }

    public function test_break_glass_reopens_local_login(): void
    {
        config(['dotsso.enabled' => true, 'dotsso.allow_local_login' => true]);
        User::factory()->create(['email' => 'old@example.test', 'password' => 'password-123']);

        $this->postJson('/api/login', ['email' => 'old@example.test', 'password' => 'password-123'])->assertOk();
        $this->register()->assertCreated();
        $this->getJson('/api/auth/config')->assertJsonPath('local_login', true);
    }

    public function test_the_post_logout_address_has_the_trailing_slash_infodot_matches_exactly(): void
    {
        $config = require base_path('config/dotsso.php');

        $this->assertStringEndsWith('/', (string) $config['post_logout_redirect_uri']);
    }

    public function test_new_tokens_expire(): void
    {
        config(['dotsso.enabled' => false, 'dotsso.token_ttl_days' => 30]);
        $this->register();

        $expires = \Laravel\Sanctum\PersonalAccessToken::firstOrFail()->expires_at;
        $this->assertNotNull($expires);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $expires->timestamp, 10);
    }

    public function test_machine_and_public_routes_are_untouched_with_the_flag_on(): void
    {
        config(['dotsso.enabled' => true]);

        $this->getJson('/api/ecosystem/switcher')->assertOk();
        $this->postJson('/api/backtests', [])->assertStatus(422);
        $this->getJson('/up')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/guardian/health')->assertStatus(503);
        $this->get('/up/scheduler')->assertStatus(503);
    }

    public function test_a_bearer_token_route_uses_the_web_session_guard_default(): void
    {
        config(['dotsso.enabled' => true]);
        $this->assertSame('web', config('dotsso.guard'));
        $user = User::factory()->create();
        $token = $user->createToken('api', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertOk();
    }
}
