<?php

namespace Tests\Feature;

use Tests\TestCase;

class GenerateDkpKeyCommandTest extends TestCase
{
    private string $tempKeyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempKeyPath = storage_path('app/private/test-dkp-' . uniqid() . '.key');
        config(['services.dkp.key_path' => $this->tempKeyPath]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempKeyPath)) {
            unlink($this->tempKeyPath);
        }
        parent::tearDown();
    }

    public function test_it_creates_a_key_file_and_prints_a_valid_public_key(): void
    {
        $this->artisan('dkp:generate-key')->assertSuccessful();

        $this->assertFileExists($this->tempKeyPath);

        $secretKey = base64_decode(trim(file_get_contents($this->tempKeyPath)));
        $derivedPublicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        $signature = sodium_crypto_sign_detached('test-message', $secretKey);
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, 'test-message', $derivedPublicKey));
    }

    public function test_it_refuses_to_overwrite_an_existing_key(): void
    {
        $this->artisan('dkp:generate-key')->assertSuccessful();
        $originalContent = file_get_contents($this->tempKeyPath);

        $this->artisan('dkp:generate-key')->assertFailed();

        $this->assertSame($originalContent, file_get_contents($this->tempKeyPath));
    }
}
