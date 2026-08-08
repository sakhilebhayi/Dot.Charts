<?php

namespace Tests\Concerns;

trait UsesDkpTestKey
{
    private string $dkpTestKeyPath;

    protected function setUpDkpTestKey(): void
    {
        $this->dkpTestKeyPath = storage_path('app/private/test-dkp-' . uniqid() . '.key');
        $directory = dirname($this->dkpTestKeyPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $keypair = sodium_crypto_sign_keypair();
        file_put_contents($this->dkpTestKeyPath, base64_encode(sodium_crypto_sign_secretkey($keypair)));

        config(['services.dkp.key_path' => $this->dkpTestKeyPath]);
    }

    protected function tearDownDkpTestKey(): void
    {
        if (isset($this->dkpTestKeyPath) && file_exists($this->dkpTestKeyPath)) {
            unlink($this->dkpTestKeyPath);
        }
    }
}
