<?php

use App\Exceptions\DebtOffsetWorkflowException;
use App\Models\DebtOffset;
use App\Models\User;
use App\Services\Debt\DebtOffsetWorkflowService;
use Illuminate\Contracts\Console\Kernel;

[$script, $basePath, $offsetId, $actorId, $action, $versionToken, $idempotencyKey, $barrierDirectory, $workerName] = $argv;

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('debt.offsets.write_mode', 'workflow');

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
    $offset = DebtOffset::query()->findOrFail((int) $offsetId);
    $actor = User::query()->findOrFail((int) $actorId);
    $service = $app->make(DebtOffsetWorkflowService::class);
    $result = $action === 'approve'
        ? $service->approve($offset, $actor, $versionToken, $idempotencyKey)
        : $service->reject($offset, $actor, 'Concurrent reject decision', $versionToken, $idempotencyKey);

    echo 'DEBT_OFFSET_WORKER_RESULT='.json_encode([
        'outcome' => 'success',
        'workflow_status' => $result['debt_offset']['workflow_status'],
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (DebtOffsetWorkflowException $exception) {
    echo 'DEBT_OFFSET_WORKER_RESULT='.json_encode([
        'outcome' => 'workflow_error',
        'error_code' => $exception->errorCode,
        'http_status' => $exception->httpStatus,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(2);
}
