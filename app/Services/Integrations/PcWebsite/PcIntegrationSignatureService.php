<?php

namespace App\Services\Integrations\PcWebsite;

class PcIntegrationSignatureService
{
    public function canonical(string $method, string $path, string $timestamp, string $nonce, string $rawBody): string
    {
        return implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            hash('sha256', $rawBody),
        ]);
    }

    public function sign(string $method, string $path, string $timestamp, string $nonce, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $this->canonical($method, $path, $timestamp, $nonce, $rawBody), $secret);
    }
}
