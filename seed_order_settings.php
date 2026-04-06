<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$settings = [
    ['order_enabled', '1', 'order', 'boolean'],
    ['order_allow_when_out_of_stock', '1', 'order', 'boolean'],
    ['order_allow_sell_reserved', '1', 'order', 'boolean'],
    ['order_allow_cross_branch', '0', 'order', 'boolean'],
    ['allow_transaction_when_out_of_stock', '0', 'order', 'boolean'],
    ['allow_print_quote', '0', 'order', 'boolean'],
    ['order_confirm_before_complete', '0', 'order', 'boolean'],
    ['return_time_limit_enabled', '1', 'order', 'boolean'],
    ['return_time_limit_days', '7', 'order', 'number'],
    ['return_overdue_action', 'warn', 'order', 'string'],
    ['block_change_transaction_time', '0', 'order', 'boolean'],
    ['block_edit_cancel_einvoice', '1', 'order', 'boolean'],
    ['manage_other_fees', '1', 'order', 'boolean'],
];

foreach ($settings as $s) {
    App\Models\Setting::set($s[0], $s[1], $s[2], $s[3]);
}
echo 'Seeded ' . count($settings) . " order settings\n";
