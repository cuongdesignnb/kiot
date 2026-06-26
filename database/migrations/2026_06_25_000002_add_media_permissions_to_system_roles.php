<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $mediaPermissions = [
        'media.view',
        'media.upload',
        'media.create_folder',
        'media.edit',
        'media.delete',
        'media.move',
    ];

    public function up(): void
    {
        Role::query()
            ->whereIn('name', ['branch_admin', 'warehouse_staff'])
            ->get()
            ->each(function (Role $role) {
                $permissions = $role->permissions ?? [];
                if (in_array('*', $permissions, true)) {
                    return;
                }

                $role->permissions = array_values(array_unique(array_merge($permissions, $this->mediaPermissions)));
                $role->save();
            });
    }

    public function down(): void
    {
        Role::query()
            ->whereIn('name', ['branch_admin', 'warehouse_staff'])
            ->get()
            ->each(function (Role $role) {
                $permissions = $role->permissions ?? [];
                if (in_array('*', $permissions, true)) {
                    return;
                }

                $role->permissions = array_values(array_diff($permissions, $this->mediaPermissions));
                $role->save();
            });
    }
};
