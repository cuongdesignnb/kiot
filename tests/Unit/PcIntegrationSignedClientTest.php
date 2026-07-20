<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\Support\PcIntegrationSignedClient;
use Tests\TestCase;

class PcIntegrationSignedClientTest extends TestCase
{
    public function test_canonical_uses_exact_raw_body_and_excludes_query_string(): void
    {
        $client = new PcIntegrationSignedClient('http://127.0.0.1:8099', 'client', 'secret');
        $rawBody = '{"sku":"Ab C/+","quantity":1}';
        $expected = implode("\n", [
            'POST',
            '/api/integrations/v1/pc/orders',
            '1700000000',
            'nonce-1',
            hash('sha256', $rawBody),
        ]);

        $this->assertSame(
            $expected,
            $client->canonical(
                'post',
                '/api/integrations/v1/pc/orders?debug=must-not-be-signed',
                '1700000000',
                'nonce-1',
                $rawBody,
            ),
        );
    }

    public function test_signature_is_lowercase_hex_and_evidence_masks_credentials(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"success":true}')]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new PcIntegrationSignedClient('http://127.0.0.1:8099', 'client-id', 'super-secret', $http);
        $headers = $client->signedHeaders(
            'GET',
            '/api/integrations/v1/pc/products?limit=1',
            '',
            'nonce-2',
            1700000000,
        );

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $headers['X-Signature']);

        $result = $client->request(
            'GET',
            '/api/integrations/v1/pc/products?limit=1',
            '',
            'idempotency-secret',
            'nonce-2',
            1700000000,
        );

        $this->assertSame('/api/integrations/v1/pc/products', $result['request']['canonical_path']);
        $this->assertSame('[REDACTED]', $result['request']['headers']['X-Signature']);
        $this->assertStringStartsWith('sha256:', $result['request']['headers']['X-Integration-Key']);
        $this->assertStringNotContainsString('client-id', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('super-secret', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('idempotency-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
