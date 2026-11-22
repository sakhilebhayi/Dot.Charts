<?php

namespace Tests\Feature\Sso;

use App\Sso\ChartsProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared plumbing: Charts' real users table, provisioner and routes, with the package's fake InfoDot (it signs real RS256
 * tokens and checks PKCE like InfoDot does). Requests go to https://platform.test, which is also the stateful SPA origin.
 */
abstract class ChartsSsoTestCase extends TestCase
{
    use RefreshDatabase;

    protected const ORIGIN = 'https://platform.test';

    protected FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->idp = (new FakeIdp)->install();
        config([
            'dotsso.provisioner' => ChartsProvisioner::class,
            'dotsso.allow_local_login' => false,
            'sanctum.stateful' => ['platform.test'],
        ]);
    }

    /** @param array<string, string> $query */
    protected function startLogin(array $query = []): array
    {
        $response = $this->get(self::ORIGIN.'/sso/login'.($query ? '?'.http_build_query($query) : ''));
        $response->assertRedirect();

        return FakeIdp::authorizeParams($response->headers->get('Location'));
    }

    protected function finishLogin(array $authorize, array $claims = []): TestResponse
    {
        $code = $this->idp->approve($authorize, $claims);

        return $this->get(self::ORIGIN.'/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    /** Sign in through the whole flow as the given InfoDot identity and return the callback response. */
    protected function signInAs(string $sub, string $email, array $query = []): TestResponse
    {
        return $this->finishLogin($this->startLogin($query), ['sub' => $sub, 'email' => $email, 'name' => 'Test Person']);
    }

    /** A same-origin SPA request: the Referer is the Charts origin, so Sanctum treats it as stateful. */
    protected function spa(): static
    {
        return $this->withHeaders(['Referer' => self::ORIGIN.'/home.html', 'Accept' => 'application/json']);
    }
}
