<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$tables = Illuminate\Support\Facades\Schema::getConnection()->getDoctrineSchemaManager()->listTableNames();
sort($tables);
echo "Tables (" . count($tables) . "):\n";
foreach ($tables as $t) echo "  $t\n";
