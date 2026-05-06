<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$cols = Illuminate\Support\Facades\Schema::getColumnListing('users');
echo "Users columns: " . implode(', ', $cols) . "\n";
$dbPath = config('database.connections.sqlite.database');
echo "DB path: $dbPath\n";
$userCount = \App\Models\User::withoutGlobalScopes()->count();
echo "User count (no scope): $userCount\n";
