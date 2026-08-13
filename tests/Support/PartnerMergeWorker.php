<?php

use App\Exceptions\PartnerMergeException;
use App\Models\Customer;
use App\Models\User;
use App\Services\PartnerMergeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

[$script, $basePath, $sourceId, $targetId, $actorId, $idempotencyKey, $barrierDirectory, $workerName] = $argv;

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('debt.offsets.write_mode', 'legacy');

$actor = User::query()->findOrFail((int) $actorId);
Auth::login($actor);

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
    $result = $app->make(PartnerMergeService::class)->merge(
        Customer::query()->findOrFail((int) $sourceId),
        Customer::query()->findOrFail((int) $targetId),
        $idempotencyKey,
    );

    echo 'PARTNER_MERGE_WORKER_RESULT='.json_encode([
        'outcome' => 'success',
        'status' => $result['status'],
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (PartnerMergeException $exception) {
    echo 'PARTNER_MERGE_WORKER_RESULT='.json_encode([
        'outcome' => 'merge_error',
        'error_code' => $exception->errorCode,
        'http_status' => $exception->httpStatus,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(2);
}
