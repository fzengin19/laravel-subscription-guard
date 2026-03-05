<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Licensing;

use JsonException;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\ValidationResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Exceptions\LicenseException;
use Throwable;

final class LicenseSignature
{
    public function sign(array $payload): string
    {
        $normalizedPayload = $this->normalizePayload($payload);
        $payloadJson = (string) json_encode($normalizedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $secretKey = $this->resolveSecretKey();
        $signature = sodium_crypto_sign_detached($payloadJson, $secretKey);
        sodium_memzero($secretKey);

        return 'SG.'.$this->encodeUrlSafe($payloadJson).'.'.$this->encodeUrlSafe($signature);
    }

    public function verify(string $licenseKey): ValidationResult
    {
        if ($licenseKey === '') {
            return new ValidationResult(false, 'License key is empty.');
        }

        $parts = explode('.', $licenseKey);

        if (count($parts) !== 3 || $parts[0] !== 'SG') {
            return new ValidationResult(false, 'License key format is invalid.');
        }

        try {
            $payloadJson = $this->decodeUrlSafe($parts[1]);
            $signature = $this->decodeUrlSafe($parts[2]);
            $publicKey = $this->resolvePublicKey();
        } catch (Throwable) {
            return new ValidationResult(false, 'License key decoding failed.');
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return new ValidationResult(false, 'License signature length is invalid.');
        }

        if (! sodium_crypto_sign_verify_detached($signature, $payloadJson, $publicKey)) {
            return new ValidationResult(false, 'License signature verification failed.');
        }

        try {
            $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new ValidationResult(false, 'License payload is not valid JSON.');
        }

        if (! is_array($payload)) {
            return new ValidationResult(false, 'License payload is invalid.');
        }

        if (($payload['v'] ?? null) !== 1) {
            return new ValidationResult(false, 'Unsupported license key version.');
        }

        if (($payload['alg'] ?? null) !== (string) config('subscription-guard.license.algorithm', 'ed25519')) {
            return new ValidationResult(false, 'License algorithm is invalid.');
        }

        return new ValidationResult(true, metadata: ['payload' => $payload]);
    }

    private function resolveSecretKey(): string
    {
        $encodedKey = (string) config('subscription-guard.license.keys.private', '');

        if ($encodedKey === '') {
            throw new LicenseException('License private key is not configured.');
        }

        $decodedKey = $this->decodeUrlSafe($encodedKey);

        if (strlen($decodedKey) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return $decodedKey;
        }

        if (strlen($decodedKey) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $pair = sodium_crypto_sign_seed_keypair($decodedKey);
            $secret = sodium_crypto_sign_secretkey($pair);
            sodium_memzero($pair);

            return $secret;
        }

        throw new LicenseException('License private key length is invalid.');
    }

    private function resolvePublicKey(): string
    {
        $encodedKey = (string) config('subscription-guard.license.keys.public', '');

        if ($encodedKey === '') {
            throw new LicenseException('License public key is not configured.');
        }

        $decodedKey = $this->decodeUrlSafe($encodedKey);

        if (strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new LicenseException('License public key length is invalid.');
        }

        return $decodedKey;
    }

    private function encodeUrlSafe(string $value): string
    {
        return sodium_bin2base64($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private function decodeUrlSafe(string $value): string
    {
        return sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
    }

    private function normalizePayload(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizePayload($value);
            }
        }

        return $payload;
    }
}
