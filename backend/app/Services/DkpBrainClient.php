<?php

namespace App\Services;

use App\Models\KnowledgePack;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for Dot.Brain's DKP Ingestion Gateway (POST /v1/dkp, per
 * brain.api.md in the Dot.Brain repo). Honest stub: no real Dot.Brain
 * endpoint exists anywhere in the ecosystem as of this build -- ~/Dot/Dot.Brain
 * is entirely design/architecture documentation (~40 markdown specs, no
 * server, no deploy config, no real hostname anywhere in it), not a
 * deployed service. See wiki.md §5 for the full audit that established this.
 *
 * This client implements the documented contract exactly -- request shape,
 * response codes (202 success, 4xx + normative error code, 429 rate limit),
 * retry policy (§8: exponential backoff, base 2s) -- so it is ready to
 * activate the moment config('services.brain.dkp_endpoint') points at
 * something real. It refuses to attempt a call while that's unconfigured
 * (publish() throws immediately) rather than silently failing against
 * nothing, and no code path in this application calls publish()
 * automatically -- it is only reachable via the dkp:publish artisan
 * command, a deliberate human action, matching this app's existing
 * convention of manual-only triggers for one-off DKP operations
 * (dkp:generate-insight, dkp:generate-incident).
 *
 * Deliberately NOT implemented: mutual TLS. brain.dkp.md §8 specifies
 * "mutual TLS per tenant + envelope signatures (defense in depth)" -- the
 * envelope-signature half is real and already required by the schema
 * itself (DkpSigner, enforced by DkpEnvelopeSchemaConformanceTest), but
 * client-certificate custody is a credential-ownership concern on the same
 * level as the DKP signing key itself (Level 3 / human-controlled per
 * platforms/dot-charts.md's own autonomy classification) and there is no
 * real certificate to hold yet.
 *
 * Also worth flagging: Dot.Brain's own docs describe two different
 * transport models for this operation. brain.api.md calls it a REST
 * endpoint (POST /v1/dkp, 202/4xx) with concrete, normative HTTP
 * semantics. brain.dkp.md §8 instead describes publish/subscribe over
 * per-tenant message topics (matching platform.dkp.json's
 * publish_topic/response_topic naming) with no broker technology named
 * anywhere. This client implements the REST contract, since it's the only
 * one of the two specific enough to build against without guessing at
 * unconfirmed infrastructure -- the same discipline this whole codebase
 * has held to for every manufacturer/vendor integration.
 */
class DkpBrainClient
{
    private const RETRY_ATTEMPTS = 5;

    private const RETRY_BASE_MS = 2000;

    private const RETRY_MAX_MS = 15 * 60 * 1000;

    private ?string $endpoint;

    public function __construct(?string $endpoint = null)
    {
        $this->endpoint = $endpoint ?? config('services.brain.dkp_endpoint');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->endpoint);
    }

    /**
     * Publishes an approved, signed Knowledge Pack to Dot.Brain's
     * Ingestion Gateway.
     *
     * @return array{status: string, receipt_id: ?string, error_code: ?string, message: ?string}
     *
     * @throws RuntimeException if no endpoint is configured, or the pack
     *                          isn't in the 'approved' (signed) state
     * @throws \Illuminate\Http\Client\ConnectionException if the endpoint
     *                                                     is configured but unreachable after retries -- a genuine
     *                                                     infrastructure failure, left uncaught here to match
     *                                                     AnalyticsServiceClient's existing convention in this codebase
     */
    public function publish(KnowledgePack $pack): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'No Dot.Brain DKP endpoint is configured (services.brain.dkp_endpoint / BRAIN_DKP_ENDPOINT env var) '
                .'-- refusing to attempt a publish against nothing. As of this build no real Dot.Brain endpoint '
                .'exists anywhere in the ecosystem; see wiki.md §5.'
            );
        }

        if ($pack->status !== 'approved') {
            throw new RuntimeException(
                "Cannot publish pack \"{$pack->pack_id}\" -- status is \"{$pack->status}\", not \"approved\". "
                .'Only approved (signed) packs conform to Dot.Brain\'s schema (DkpEnvelopeSchemaConformanceTest).'
            );
        }

        // brain.dkp.md §8: "publisher retries with exponential backoff
        // (base 2s, max 15min, jitter)". 5 attempts (2s/4s/8s/16s/32s,
        // capped at 15min per attempt) rather than looping for the
        // documented ceiling inside one synchronous request -- a real
        // deployment would run this from a queued job, not inline.
        // throw: false because a non-2xx response here is meaningful data
        // to inspect (which DKP_* error code, or a legitimate 429), not
        // an exception to raise -- unlike AnalyticsServiceClient's
        // simpler client, where any non-2xx really is just a failure.
        $response = Http::timeout(30)
            ->retry(
                self::RETRY_ATTEMPTS,
                fn (int $attempt) => min(self::RETRY_BASE_MS * (2 ** ($attempt - 1)), self::RETRY_MAX_MS),
                throw: false,
            )
            ->post($this->endpoint, $pack->envelope);

        if ($response->status() === 202) {
            return [
                'status' => 'ingested',
                'receipt_id' => $response->json('receipt_id'),
                'error_code' => null,
                'message' => null,
            ];
        }

        if ($response->status() === 429) {
            return [
                'status' => 'rate_limited',
                'receipt_id' => null,
                'error_code' => 'DKP_RATE_LIMITED',
                'message' => 'Rate limited; retry after '
                    .($response->header('Retry-After') ?: $response->json('retry_after') ?: 'an unspecified interval').'.',
            ];
        }

        $errorCode = $response->json('error_code') ?? $response->json('code');

        // DKP_DUPLICATE is explicitly a non-error ack per brain.dkp.md's
        // normative error-codes list (pack_id+pack_version already
        // ingested) -- treat it as success, not a rejection.
        if ($errorCode === 'DKP_DUPLICATE') {
            return [
                'status' => 'already_ingested',
                'receipt_id' => $response->json('receipt_id'),
                'error_code' => 'DKP_DUPLICATE',
                'message' => null,
            ];
        }

        return [
            'status' => 'rejected',
            'receipt_id' => null,
            'error_code' => $errorCode,
            'message' => $response->json('message') ?? $response->body(),
        ];
    }
}
