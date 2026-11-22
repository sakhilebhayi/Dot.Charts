<?php

namespace Tests\Feature\Sso;

use App\Models\BacktestRun;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Session-cookie authentication for the SPA (Sanctum stateful), CSRF, bearer coexistence, logout and tenant isolation. */
class SessionApiTest extends ChartsSsoTestCase
{
    private function signedIn(string $sub = 'sub-a', string $email = 'a@example.test'): User
    {
        $this->signInAs($sub, $email)->assertRedirect();

        return User::where('email', $email)->firstOrFail();
    }

    /** Laravel skips the CSRF check while its environment is "testing"; these tests need it on. */
    private function realCsrf(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_after_sign_in_the_spa_is_authenticated_by_the_session_with_no_token(): void
    {
        $user = $this->signedIn();

        $this->spa()->getJson('/api/me')->assertOk()->assertJsonPath('email', 'a@example.test')->assertJsonPath('id', $user->id);
        $this->assertSame(0, $user->tokens()->count(), 'no API token was minted for the session');
    }

    /**
     * The test client keeps one session store alive across requests, so "the cookie is ignored" is asserted where it is
     * decided: the stateful middleware only starts a session for a request whose Referer/Origin is the Charts origin.
     */
    public function test_only_a_request_from_the_charts_origin_gets_a_session(): void
    {
        $probe = fn (string $referer, array $server = []) => (function () use ($referer, $server) {
            $request = \Illuminate\Http\Request::create('/api/me', 'GET', [], [], [], array_merge(['HTTP_REFERER' => $referer], $server));
            $started = null;
            app(\App\Http\Middleware\StatefulWhenSso::class)->handle($request, function ($r) use (&$started) {
                $started = $r->hasSession() && $r->session()->isStarted();

                return response('');
            });

            return $started;
        })();

        $this->assertTrue($probe(self::ORIGIN.'/home.html'));
        $this->assertFalse($probe('https://evil.test/'));
        $this->assertFalse($probe(self::ORIGIN.'.evil.test/'));
        $this->assertFalse($probe(self::ORIGIN.'/home.html', ['HTTP_AUTHORIZATION' => 'Bearer abc']), 'a bearer call never uses the cookie');
        config(['dotsso.enabled' => false]);
        $this->assertFalse($probe(self::ORIGIN.'/home.html'), 'with the flag off nothing is stateful');
    }

    public function test_the_public_backtest_route_is_stateful_only_for_a_person_with_a_session_cookie(): void
    {
        $started = function (array $cookies) {
            $request = \Illuminate\Http\Request::create('/api/backtests', 'POST', [], $cookies, [], ['HTTP_REFERER' => self::ORIGIN.'/index.html']);
            $result = null;
            app(\App\Http\Middleware\StatefulWhenSso::class)->handle($request, function ($r) use (&$result) {
                $result = $r->hasSession() && $r->session()->isStarted();

                return response('');
            }, 'optional');

            return $result;
        };

        $this->assertFalse($started([]), 'an anonymous visitor stays stateless, so needs no CSRF token');
        $this->assertTrue($started([config('session.cookie') => 'abc']), 'a signed-in person is attached to the run');
    }

    public function test_a_session_write_without_the_csrf_token_is_refused_and_with_it_is_accepted(): void
    {
        $this->signedIn();
        $this->realCsrf();

        $this->spa()->postJson('/api/journal-entries', ['title' => 'x', 'body' => 'y'])->assertStatus(419);
        $this->assertSame(0, JournalEntry::count());

        $token = session()->token();
        $this->spa()->withHeader('X-CSRF-TOKEN', $token)->postJson('/api/journal-entries', ['title' => 'x', 'body' => 'y'])->assertCreated();
    }

    public function test_the_csrf_cookie_route_exists_for_the_spa(): void
    {
        $this->spa()->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
    }

    public function test_session_users_only_see_their_own_data(): void
    {
        $a = $this->signedIn('sub-a', 'a@example.test');
        $b = User::factory()->create();
        $mine = JournalEntry::factory()->create(['user_id' => $a->id, 'title' => 'mine']);
        $theirs = JournalEntry::factory()->create(['user_id' => $b->id, 'title' => 'theirs']);
        $theirRun = BacktestRun::factory()->create(['user_id' => $b->id]);

        $this->spa()->getJson('/api/journal-entries/'.$mine->id)->assertOk();
        $this->spa()->getJson('/api/journal-entries/'.$theirs->id)->assertNotFound();
        $this->spa()->getJson('/api/backtests/'.$theirRun->id)->assertNotFound();
        $this->spa()->deleteJson('/api/journal-entries/'.$theirs->id)->assertNotFound();
        $this->assertDatabaseHas('journal_entries', ['id' => $theirs->id]);
    }

    public function test_a_valid_unexpired_bearer_token_still_works_with_sso_on_and_needs_no_csrf(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api', ['*'], now()->addDay())->plainTextToken;
        $this->realCsrf();

        $this->withToken($token)->getJson('/api/me')->assertOk();
        // Even from the Charts origin: a bearer call is a token call, not a cookie call.
        $this->withToken($token)->withHeaders(['Referer' => self::ORIGIN.'/home.html'])
            ->postJson('/api/journal-entries', ['title' => 't', 'body' => 'b'])->assertCreated();
    }

    public function test_an_expired_token_is_refused_and_a_pre_r54_token_with_no_expiry_still_works_until_revoked(): void
    {
        $user = User::factory()->create();
        $expired = $user->createToken('api', ['*'], now()->subMinute())->plainTextToken;
        $legacy = $user->createToken('api')->plainTextToken;

        $this->withToken($expired)->getJson('/api/me')->assertUnauthorized();
        $this->withToken($legacy)->getJson('/api/me')->assertOk();

        $this->artisan('tokens:revoke-legacy')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->artisan('tokens:revoke-legacy', ['--force' => true])->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->withToken($legacy)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_a_back_channel_logout_ends_the_session_for_api_calls_too(): void
    {
        $this->signedIn();
        $this->spa()->getJson('/api/me')->assertOk();

        DB::table('dotsso_revocations')->insert(['sub' => 'sub-a', 'revoked_at_ms' => (int) (microtime(true) * 1000) + 5000, 'created_at' => now(), 'updated_at' => now()]);

        $this->spa()->getJson('/api/me')->assertUnauthorized();
    }

    public function test_sso_logout_ends_the_session_and_returns_the_infodot_end_session_address(): void
    {
        $this->signedIn();

        $response = $this->spa()->postJson('/api/auth/sso-logout')->assertOk();

        $target = $response->json('redirect');
        $this->assertStringStartsWith('https://idp.test/oauth/end-session?', $target, 'the issuer used by the fake provider');
        parse_str((string) parse_url($target, PHP_URL_QUERY), $q);
        $this->assertNotEmpty($q['id_token_hint']);
        $this->assertSame('https://platform.test/', $q['post_logout_redirect_uri']);
        $this->assertNotNull(collect($response->baseResponse->headers->getCookies())->first(fn ($c) => $c->getName() === 'charts_signed_in' && $c->isCleared()));

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->spa()->getJson('/api/me')->assertUnauthorized();
    }

    public function test_sso_logout_needs_the_csrf_token(): void
    {
        $this->signedIn();
        $this->realCsrf();

        $this->spa()->postJson('/api/auth/sso-logout')->assertStatus(419);
    }

    public function test_the_legacy_logout_endpoint_ends_a_session_without_an_error(): void
    {
        $this->signedIn();

        $this->spa()->postJson('/api/logout')->assertOk();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->spa()->getJson('/api/me')->assertUnauthorized();
    }

    public function test_the_legacy_logout_endpoint_still_revokes_a_bearer_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_public_endpoints_need_neither_session_nor_csrf(): void
    {
        $this->realCsrf();

        $this->withHeaders(['Referer' => self::ORIGIN.'/index.html'])->postJson('/api/backtests', [])->assertStatus(422);
        $this->withHeaders(['Referer' => self::ORIGIN.'/index.html'])->getJson('/api/ecosystem/switcher')->assertOk();
    }
}
