<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateDkpKey extends Command
{
    protected $signature = 'dkp:generate-key';

    protected $description = 'Generate the Ed25519 signing keypair used to sign Knowledge Packs. Refuses to overwrite an existing key.';

    public function handle(): int
    {
        $path = config('services.dkp.key_path');

        if (file_exists($path)) {
            $this->error("Key already exists at {$path} -- refusing to overwrite. Regenerating would invalidate every previously-signed pack's verifiability against the manifest's committed public key.");

            return self::FAILURE;
        }

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($path, base64_encode($secretKey));
        chmod($path, 0600);

        $this->info('Key generated at ' . $path);
        $this->info('Public key (paste into platform.dkp.json keys[0].public_key):');
        $this->line(base64_encode($publicKey));

        return self::SUCCESS;
    }
}
