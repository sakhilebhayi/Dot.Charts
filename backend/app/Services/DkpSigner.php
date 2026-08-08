<?php

namespace App\Services;

class DkpSigner
{
    private const KEY_ID = 'dot-charts-dkp-v1';

    public function __construct(private readonly ?string $keyPath = null)
    {
    }

    public function canonicalize(array $data): string
    {
        $this->recursiveKsort($data);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function sign(array $envelope): array
    {
        $withoutSignatures = $envelope;
        unset($withoutSignatures['signatures']);

        $canonical = $this->canonicalize($withoutSignatures);
        $signatureBytes = sodium_crypto_sign_detached($canonical, $this->secretKey());

        return [[
            'key_id' => self::KEY_ID,
            'algorithm' => 'ed25519-jcs',
            'signed_at' => now()->toIso8601String(),
            'value' => base64_encode($signatureBytes),
        ]];
    }

    public function verify(array $envelope): bool
    {
        if (empty($envelope['signatures'][0]['value'])) {
            return false;
        }

        $withoutSignatures = $envelope;
        unset($withoutSignatures['signatures']);
        $canonical = $this->canonicalize($withoutSignatures);

        $signatureBytes = base64_decode($envelope['signatures'][0]['value']);

        return sodium_crypto_sign_verify_detached($signatureBytes, $canonical, $this->publicKey());
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
    }

    private function secretKey(): string
    {
        $path = $this->keyPath ?? config('services.dkp.key_path');

        return base64_decode(trim(file_get_contents($path)));
    }

    private function recursiveKsort(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveKsort($value);
            }
        }
    }
}
