<?php

namespace Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class PcIntegrationSignedClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $secret,
        private readonly ClientInterface $http = new Client,
    ) {}

    public static function fromEnvironment(): self
    {
        $baseUrl = trim((string) getenv('PC_SMOKE_BASE_URL'));
        $clientId = trim((string) getenv('PC_INTEGRATION_CLIENT_ID'));
        $secret = (string) getenv('PC_INTEGRATION_SECRET');

        if ($baseUrl === '' || $clientId === '' || $secret === '') {
            throw new RuntimeException(
                'PC_SMOKE_BASE_URL, PC_INTEGRATION_CLIENT_ID and PC_INTEGRATION_SECRET are required.'
            );
        }

        return new self(rtrim($baseUrl, '/'), $clientId, $secret);
    }

    public function canonical(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $rawBody,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $this->canonicalPath($path),
            $timestamp,
            $nonce,
            hash('sha256', $rawBody),
        ]);
    }

    public function signedHeaders(
        string $method,
        string $path,
        string $rawBody = '',
        ?string $nonce = null,
        ?int $timestamp = null,
        ?string $idempotencyKey = null,
    ): array {
        $nonce ??= $this->uuid();
        $timestamp ??= time();
        $signature = hash_hmac(
            'sha256',
            $this->canonical($method, $path, (string) $timestamp, $nonce, $rawBody),
            $this->secret,
        );

        return array_filter([
            'X-Integration-Key' => $this->clientId,
            'X-Timestamp' => (string) $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'Idempotency-Key' => $idempotencyKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], fn ($value) => $value !== null);
    }

    public function request(
        string $method,
        string $path,
        string $rawBody = '',
        ?string $idempotencyKey = null,
        ?string $nonce = null,
        ?int $timestamp = null,
        array $headerOverrides = [],
    ): array {
        $headers = array_merge(
            $this->signedHeaders($method, $path, $rawBody, $nonce, $timestamp, $idempotencyKey),
            $headerOverrides,
        );
        $response = $this->http->request(strtoupper($method), $this->baseUrl.$path, [
            'headers' => $headers,
            'body' => $rawBody,
            'http_errors' => false,
            'timeout' => 30,
        ]);

        return $this->evidence($method, $path, $rawBody, $headers, $response);
    }

    public function concurrentReplay(
        string $path,
        string $rawBody = '',
        ?string $nonce = null,
        ?int $timestamp = null,
    ): array {
        $headers = $this->signedHeaders('GET', $path, $rawBody, $nonce, $timestamp);
        $options = [
            'headers' => $headers,
            'body' => $rawBody,
            'http_errors' => false,
            'timeout' => 30,
        ];
        $settled = Utils::settle([
            $this->http->requestAsync('GET', $this->baseUrl.$path, $options),
            $this->http->requestAsync('GET', $this->baseUrl.$path, $options),
        ])->wait();

        return collect($settled)->map(function (array $result) use ($path, $rawBody, $headers): array {
            if (($result['state'] ?? null) !== 'fulfilled' || ! ($result['value'] ?? null) instanceof ResponseInterface) {
                $reason = $result['reason'] ?? null;
                throw new RuntimeException('Concurrent signed request failed: '.($reason instanceof \Throwable ? $reason->getMessage() : 'unknown error'));
            }

            return $this->evidence('GET', $path, $rawBody, $headers, $result['value']);
        })->values()->all();
    }

    private function evidence(
        string $method,
        string $path,
        string $rawBody,
        array $headers,
        ResponseInterface $response,
    ): array {
        $responseBody = (string) $response->getBody();
        $decoded = json_decode($responseBody, true);

        return [
            'request' => [
                'method' => strtoupper($method),
                'path' => $path,
                'canonical_path' => $this->canonicalPath($path),
                'payload_hash' => hash('sha256', $rawBody),
                'headers' => $this->maskedHeaders($headers),
            ],
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : ['_non_json_response' => true],
        ];
    }

    private function canonicalPath(string $path): string
    {
        $canonicalPath = parse_url($path, PHP_URL_PATH);

        return is_string($canonicalPath) && $canonicalPath !== '' ? $canonicalPath : '/';
    }

    private function maskedHeaders(array $headers): array
    {
        if (isset($headers['X-Integration-Key'])) {
            $headers['X-Integration-Key'] = 'sha256:'.substr(hash('sha256', (string) $headers['X-Integration-Key']), 0, 12);
        }
        if (isset($headers['X-Signature'])) {
            $headers['X-Signature'] = '[REDACTED]';
        }
        if (isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = 'sha256:'.substr(hash('sha256', (string) $headers['Idempotency-Key']), 0, 12);
        }

        return $headers;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
