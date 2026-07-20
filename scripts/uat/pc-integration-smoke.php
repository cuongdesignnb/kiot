<?php

declare(strict_types=1);

use Tests\Support\PcIntegrationSignedClient;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function requiredEnvironment(string $name): string
{
    $value = trim((string) getenv($name));

    if ($value === '') {
        throw new RuntimeException("{$name} is required.");
    }

    return $value;
}

function uuid(): string
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

function encodeJson(array $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
    );
}

function assertResponse(array $result, int $expectedStatus, ?string $expectedErrorCode = null): void
{
    if ($result['status'] !== $expectedStatus) {
        throw new RuntimeException(sprintf(
            'Expected HTTP %d for %s %s, received %d.',
            $expectedStatus,
            $result['request']['method'],
            $result['request']['path'],
            $result['status'],
        ));
    }

    if ($expectedErrorCode !== null && ($result['body']['error']['code'] ?? null) !== $expectedErrorCode) {
        throw new RuntimeException(sprintf(
            'Expected error code %s for %s %s.',
            $expectedErrorCode,
            $result['request']['method'],
            $result['request']['path'],
        ));
    }
}

try {
    $client = PcIntegrationSignedClient::fromEnvironment();
    $sku = requiredEnvironment('PC_SMOKE_NORMAL_SKU');
    $unitPrice = (int) (getenv('PC_SMOKE_UNIT_PRICE') ?: 800000);
    $runId = gmdate('YmdHis').'-'.substr(str_replace('-', '', uuid()), 0, 8);
    $externalOrderId = trim((string) getenv('PC_SMOKE_EXTERNAL_ORDER_ID')) ?: 'PC-UAT-'.$runId;
    $externalOrderCode = 'WEB-UAT-'.$runId;
    $orderPath = '/api/integrations/v1/pc/orders';
    $productPath = '/api/integrations/v1/pc/products';
    $detailPath = $productPath.'/'.rawurlencode($sku);

    $payload = [
        'event_id' => uuid(),
        'external_order_id' => $externalOrderId,
        'external_order_code' => $externalOrderCode,
        'ordered_at' => gmdate(DATE_ATOM),
        'customer' => [
            'name' => 'PC Integration UAT',
            'phone' => '0987654321',
            'email' => 'pc-uat-'.$runId.'@example.test',
        ],
        'delivery' => [
            'is_delivery' => false,
            'receiver_name' => null,
            'receiver_phone' => null,
            'receiver_address' => null,
            'receiver_ward' => null,
            'receiver_district' => null,
            'receiver_city' => null,
            'weight' => 500,
            'shipping_fee' => 0,
        ],
        'payment' => ['method' => 'sepay', 'status' => 'paid'],
        'totals' => [
            'subtotal' => $unitPrice,
            'discount' => 0,
            'shipping_fee' => 0,
            'total' => $unitPrice,
        ],
        'items' => [[
            'sku' => $sku,
            'product_name' => 'PC Integration UAT Product',
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'discount' => 0,
            'line_total' => $unitPrice,
            'bundle_ref' => null,
        ]],
        'note' => 'KIOT provider verification smoke',
    ];
    $rawOrder = encodeJson($payload);
    $idempotencyKey = 'pc-uat-order-'.$runId;
    $results = [];

    $results['product_list_query_excluded_from_signature'] = $client->request(
        'GET',
        $productPath.'?limit=1&sku='.rawurlencode($sku),
    );
    assertResponse($results['product_list_query_excluded_from_signature'], 200);

    $results['product_detail_url_encoded_sku'] = $client->request('GET', $detailPath);
    assertResponse($results['product_detail_url_encoded_sku'], 200);

    $results['invalid_signature'] = $client->request(
        'GET',
        $productPath,
        '',
        null,
        null,
        null,
        ['X-Signature' => str_repeat('0', 64)],
    );
    assertResponse($results['invalid_signature'], 401, 'INVALID_SIGNATURE');

    $results['concurrent_nonce_replay'] = $client->concurrentReplay($productPath, '', uuid());
    $replayStatuses = collect($results['concurrent_nonce_replay'])->pluck('status')->sort()->values()->all();
    if ($replayStatuses !== [200, 409]) {
        throw new RuntimeException('Concurrent nonce replay must produce exactly one HTTP 200 and one HTTP 409.');
    }
    $replayErrorCodes = collect($results['concurrent_nonce_replay'])->pluck('body.error.code')->filter()->values()->all();
    if ($replayErrorCodes !== ['REPLAYED_NONCE']) {
        throw new RuntimeException('Concurrent nonce replay must produce REPLAYED_NONCE exactly once.');
    }

    $results['order_create'] = $client->request('POST', $orderPath, $rawOrder, $idempotencyKey);
    assertResponse($results['order_create'], 201);

    $results['order_duplicate'] = $client->request('POST', $orderPath, $rawOrder, $idempotencyKey);
    assertResponse($results['order_duplicate'], 200);
    if (($results['order_duplicate']['body']['duplicate'] ?? null) !== true) {
        throw new RuntimeException('Expected duplicate=true for identical order retry.');
    }

    $conflictPayload = $payload;
    $conflictPayload['event_id'] = uuid();
    $conflictPayload['note'] = 'Changed payload must conflict';
    $results['external_order_conflict'] = $client->request(
        'POST',
        $orderPath,
        encodeJson($conflictPayload),
        'pc-uat-conflict-'.$runId,
    );
    assertResponse($results['external_order_conflict'], 409, 'EXTERNAL_ORDER_CONFLICT');

    $statusPath = $orderPath.'/'.rawurlencode($externalOrderId);
    $results['order_status'] = $client->request('GET', $statusPath);
    assertResponse($results['order_status'], 200);

    $cancelPath = $statusPath.'/cancel';
    $cancelRaw = encodeJson(['event_id' => uuid(), 'reason' => 'Provider verification cleanup']);
    $results['order_cancel'] = $client->request(
        'POST',
        $cancelPath,
        $cancelRaw,
        'pc-uat-cancel-'.$runId,
    );
    assertResponse($results['order_cancel'], 200);

    $results['availability_after_cancel'] = $client->request(
        'GET',
        $productPath.'?limit=1&sku='.rawurlencode($sku),
    );
    assertResponse($results['availability_after_cancel'], 200);

    $evidence = [
        'success' => true,
        'generated_at_utc' => gmdate(DATE_ATOM),
        'run_id' => $runId,
        'external_order_id' => $externalOrderId,
        'results' => $results,
    ];
    $encodedEvidence = encodeJson($evidence).PHP_EOL;
    $evidenceFile = trim((string) getenv('PC_SMOKE_EVIDENCE_FILE'));

    if ($evidenceFile !== '') {
        $directory = dirname($evidenceFile);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create evidence directory: {$directory}");
        }
        if (file_put_contents($evidenceFile, $encodedEvidence) === false) {
            throw new RuntimeException("Unable to write evidence file: {$evidenceFile}");
        }
    }

    fwrite(STDOUT, $encodedEvidence);
} catch (Throwable $exception) {
    fwrite(STDERR, encodeJson([
        'success' => false,
        'error' => [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
        ],
    ]).PHP_EOL);
    exit(1);
}
