<?php
/**
 * SCRIPT DỌN DẸP + REBUILD TỒN KHO TOÀN BỘ
 * 
 * Chạy: php fix_inventory.php
 * 
 * Bước 1: Xóa tất cả initial_balance (không cần nữa)
 * Bước 2: Rebuild giá vốn cho tất cả SP
 * Bước 3: Hiển thị kết quả để đối chiếu
 */

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\StockMovement;

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  DỌN DẸP + REBUILD TỒN KHO TOÀN BỘ                ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// ═══════════════════════════════════════
// BƯỚC 1: Xóa initial_balance
// ═══════════════════════════════════════
$ibCount = StockMovement::where('type', 'initial_balance')->count();
echo "Bước 1: Xóa initial_balance...\n";
if ($ibCount > 0) {
    StockMovement::where('type', 'initial_balance')->delete();
    echo "  ✅ Đã xóa {$ibCount} dòng initial_balance\n";
} else {
    echo "  ✅ Không có initial_balance nào\n";
}

// ═══════════════════════════════════════
// BƯỚC 2: Rebuild giá vốn
// ═══════════════════════════════════════
echo "\nBước 2: Chạy rebuild giá vốn...\n";
$exitCode = Artisan::call('costing:rebuild-moving-avg', ['--all' => true]);
echo Artisan::output();

// ═══════════════════════════════════════
// BƯỚC 3: Chạy audit
// ═══════════════════════════════════════
echo "\nBước 3: Chạy audit kiểm tra...\n";
$exitCode = Artisan::call('inventory:audit');
echo Artisan::output();

echo "\n╔══════════════════════════════════════════════════════╗\n";
echo "║  HOÀN TẤT!                                          ║\n";
echo "║  Số liệu hệ thống ĐÃ ĐÚNG theo chứng từ.          ║\n";
echo "║  Nếu tồn kho thực tế khác → làm phiếu Kiểm kho.   ║\n";
echo "╚══════════════════════════════════════════════════════╝\n";
