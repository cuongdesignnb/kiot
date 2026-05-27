<?php

namespace App\Console\Commands;

use App\Models\Role;
use Illuminate\Console\Command;

/**
 * Step 24.0B — Cấp các permission keys mới (do step 24.0B bổ sung) cho các
 * role admin/system hiện có, để khi enforce middleware không khóa nhầm role
 * sẵn có quyền tổng.
 *
 * Logic:
 *   - Mặc định dry-run (in ra plan, không ghi DB).
 *   - --commit mới ghi.
 *   - Đối tượng cấp (mặc định, an toàn):
 *     1) Role có permissions chứa '*' (full admin) — đã có wildcard nên skip
 *        nhưng được hiển thị để xác nhận. Không cần ghi gì.
 *     2) Role chỉ định qua --role=ID hoặc --role-name=NAME (manual).
 *   - Role `is_system=true` không tự động được cấp (vì không nhất thiết là
 *     admin — ví dụ "Thu ngân" cũng có thể là system role nhưng không nên
 *     có quyền hủy hóa đơn hàng loạt). Để cấp thì dùng --role-name explicit.
 *   - User role_id = NULL được hệ thống coi là admin bypass (User::isAdmin),
 *     không cần cấp gì.
 *   - User role_id = NULL được hệ thống coi là admin bypass (User::isAdmin),
 *     không cần cấp gì.
 *   - Idempotent: chạy nhiều lần không tạo duplicate.
 *   - KHÔNG xóa permission cũ.
 */
class GrantSensitivePermissions extends Command
{
    protected $signature = 'permissions:grant-sensitive
                            {--commit : Ghi DB. Mặc định dry-run.}
                            {--role= : Role ID cụ thể cần cấp.}
                            {--role-name= : Role name cụ thể cần cấp.}';

    protected $description = 'Cấp permission keys mới (Step 24.0B) cho admin/system role một cách an toàn.';

    /**
     * Permissions mới cần cấp cho role tổng (admin/system).
     * Bất cứ permission nào trong danh sách này nếu không có trong role.permissions sẽ được thêm.
     */
    protected array $newPermissions = [
        // Sales
        'invoices.cancel',
        'invoices.edit',
        'returns.cancel',

        // Purchases
        'purchases.cancel',
        'purchases.return.create',
        'purchases.return.cancel',

        // Inventory
        'stock_transfers.receive',
        'stock_transfers.cancel',
        'stock_takes.balance',
        'stock_takes.cancel',
        'damages.cancel',

        // Repair / Warranty
        'tasks.create_external',
        'tasks.complete_external',
        'tasks.attach_warranty',
        'tasks.apply_warranty_policy',
        'tasks.disassemble',

        // System
        'system.audit.view',
    ];

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $roleId = $this->option('role');
        $roleName = $this->option('role-name');

        $query = Role::query();

        if ($roleId) {
            $query->where('id', $roleId);
        } elseif ($roleName) {
            $query->where('name', $roleName);
        } else {
            // Default an toàn: chỉ role có wildcard '*'.
            // is_system không đủ tin cậy (ví dụ "Thu ngân" có thể là system role
            // nhưng không nên có quyền hủy hóa đơn hàng loạt).
            $query->whereJsonContains('permissions', '*');
        }

        $roles = $query->get();

        if ($roles->isEmpty()) {
            $this->warn('Không tìm thấy role phù hợp để cấp.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line(sprintf(
            '<info>%s</info> %d permission key(s), %d role(s).',
            $commit ? 'COMMIT' : 'DRY-RUN',
            count($this->newPermissions),
            $roles->count()
        ));
        $this->line('');

        $totalGranted = 0;
        foreach ($roles as $role) {
            $current = is_array($role->permissions) ? $role->permissions : [];
            $hasWildcard = in_array('*', $current, true);

            if ($hasWildcard) {
                $this->line(sprintf(
                    '  <comment>[skip]</comment> Role #%d "%s" đã có wildcard `*` → tự pass mọi permission.',
                    $role->id,
                    $role->display_name ?: $role->name
                ));
                continue;
            }

            $missing = array_values(array_diff($this->newPermissions, $current));

            if (empty($missing)) {
                $this->line(sprintf(
                    '  <comment>[ok ]</comment> Role #%d "%s" đã đủ permission, không cần cấp thêm.',
                    $role->id,
                    $role->display_name ?: $role->name
                ));
                continue;
            }

            $this->line(sprintf(
                '  <info>[+%d]</info> Role #%d "%s": cấp thêm %s',
                count($missing),
                $role->id,
                $role->display_name ?: $role->name,
                implode(', ', $missing)
            ));
            $totalGranted += count($missing);

            if ($commit) {
                $merged = array_values(array_unique(array_merge($current, $missing)));
                $role->permissions = $merged;
                $role->save();
            }
        }

        $this->line('');
        if ($commit) {
            $this->info("Tổng cộng đã cấp {$totalGranted} permission(s).");
        } else {
            $this->warn("DRY-RUN — không có gì ghi DB. Chạy lại với --commit để áp dụng.");
            $this->line("Sẽ cấp tổng cộng {$totalGranted} permission(s).");
        }

        return self::SUCCESS;
    }
}
