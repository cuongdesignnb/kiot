<?php

use App\Exceptions\PcIntegrationException;
use App\Services\Integrations\PcWebsite\PcOrderImportService;
use Illuminate\Contracts\Console\Kernel;

[$script, $basePath, $branchId, $encodedPayload, $idempotencyKey, $barrierDirectory, $workerName] = $argv;

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('integrations.pc_website.enabled', true);
config()->set('integrations.pc_website.client_id', 'pc-concurrency-worker');
config()->set('integrations.pc_website.secret', 'pc-concurrency-worker-secret-with-sufficient-entropy');
config()->set('integrations.pc_website.default_branch_id', (int) $branchId);
config()->set('integrations.pc_website.sales_channel', 'Website PC');
config()->set('integrations.pc_website.reservation_ttl_minutes', 1440);

$rawBody = base64_decode($encodedPayload, true);
if ($rawBody === false) {
    fwrite(STDERR, "Invalid worker payload.\n");
    exit(4);
}
$payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

file_put_contents($barrierDirectory.'/'.$workerName.'.ready', 'ready');
$deadline = microtime(true) + 10;
while (! is_file($barrierDirectory.'/release')) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Concurrency barrier timed out.\n");
        exit(3);
    }
    usleep(20_000);
}

try {
    $result = $app->make(PcOrderImportService::class)->import($payload, $idempotencyKey, $rawBody);
    echo 'PC_ORDER_WORKER_RESULT='.json_encode([
        'outcome' => 'success',
        'duplicate' => $result['duplicate'],
        'order_id' => $result['order']->id,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (PcIntegrationException $exception) {
    echo 'PC_ORDER_WORKER_RESULT='.json_encode([
        'outcome' => 'integration_error',
        'error_code' => $exception->errorCode,
        'http_status' => $exception->httpStatus,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(2);
}
