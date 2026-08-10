<?php

namespace App\Console\Commands;

use App\Models\KnowledgePack;
use App\Services\DkpBrainClient;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Manual-only, matching this app's existing convention for one-off DKP
 * operations (dkp:generate-insight, dkp:generate-incident) -- nothing in
 * this application calls DkpBrainClient::publish() automatically. Given
 * there is no real Dot.Brain endpoint to publish to yet (see
 * DkpBrainClient's own docblock and wiki.md §5), this command exists to be
 * ready the moment one is configured, not to run unattended today.
 */
class PublishKnowledgePack extends Command
{
    protected $signature = 'dkp:publish {id : The KnowledgePack primary key, not the dkp: pack_id string}';

    protected $description = 'Publish an approved Knowledge Pack to Dot.Brain\'s DKP Ingestion Gateway. No-op with a clear message if no endpoint is configured yet.';

    public function handle(DkpBrainClient $client): int
    {
        $pack = KnowledgePack::find($this->argument('id'));

        if ($pack === null) {
            $this->error("No Knowledge Pack with id {$this->argument('id')}.");

            return self::FAILURE;
        }

        if (! $client->isConfigured()) {
            $this->warn(
                'No Dot.Brain DKP endpoint is configured yet (services.brain.dkp_endpoint / BRAIN_DKP_ENDPOINT). '
                .'This is expected as of this build -- no real Dot.Brain endpoint exists anywhere in the '
                .'ecosystem yet. See wiki.md §5.'
            );

            return self::FAILURE;
        }

        try {
            $result = $client->publish($pack);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        match ($result['status']) {
            'ingested' => $this->info("Published {$pack->pack_id} -- receipt {$result['receipt_id']}."),
            'already_ingested' => $this->info("Already published: {$pack->pack_id} (DKP_DUPLICATE ack)."),
            'rate_limited' => $this->warn("Rate limited: {$result['message']}"),
            default => $this->error("Rejected: {$result['error_code']} -- {$result['message']}"),
        };

        return in_array($result['status'], ['ingested', 'already_ingested'], true) ? self::SUCCESS : self::FAILURE;
    }
}
