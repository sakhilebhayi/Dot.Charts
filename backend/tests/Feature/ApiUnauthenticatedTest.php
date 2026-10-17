<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * An API client that forgets `Accept: application/json` must still get
 * machine-readable answers. Before 2026-10-17 a protected route answered
 * HTTP 500 (the default guest redirect calls route('login'), which Charts
 * does not have) and a failed validation answered a 302 to the home page.
 */
class ApiUnauthenticatedTest extends TestCase
{
    public function test_protected_routes_answer_401_json_without_an_accept_header(): void
    {
        foreach (['/api/me', '/api/strategies', '/api/backtests', '/api/journal-entries'] as $uri) {
            $response = $this->call('GET', $uri);

            $response->assertStatus(401);
            $response->assertHeader('content-type', 'application/json');
            $response->assertJsonPath('message', 'Unauthenticated.');
        }
    }

    public function test_a_failed_validation_on_a_public_route_is_a_422_json_not_a_redirect(): void
    {
        $response = $this->call('POST', '/api/backtests');

        $response->assertStatus(422);
        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonValidationErrors(['symbol', 'strategy']);
    }

    public function test_unknown_api_routes_are_json_404s(): void
    {
        $this->call('GET', '/api/__nope__')->assertStatus(404)->assertHeader('content-type', 'application/json');
    }
}
