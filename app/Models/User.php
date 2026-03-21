<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'branch_id',
        'phone',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ── Relationships ──

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchAccess()
    {
        return $this->belongsToMany(Branch::class, 'user_branch_access');
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function taskComments()
    {
        return $this->hasMany(TaskComment::class);
    }

    // ── Permission helpers ──

    /**
     * User without role_id is treated as admin (backward compatible).
     */
    public function isAdmin(): bool
    {
        // Only the root user (id=1) is admin without a role
        if ($this->role_id === null) {
            return $this->id === 1;
        }
        return $this->role && $this->role->hasPermission('*');
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        return $this->role && $this->role->hasPermission($permission);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if ($this->hasPermission($p)) {
                return true;
            }
        }
        return false;
    }

    public function hasRole(string $roleName): bool
    {
        return $this->role && $this->role->name === $roleName;
    }

    public function hasAnyRole(array $roleNames): bool
    {
        return $this->role && in_array($this->role->name, $roleNames);
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    /**
     * Get all branch IDs this user can access.
     */
    public function getAccessibleBranchIds(): array
    {
        $ids = $this->branchAccess()->pluck('branches.id')->toArray();
        if ($this->branch_id && !in_array($this->branch_id, $ids)) {
            $ids[] = $this->branch_id;
        }
        return $ids;
    }

    /**
     * Returns permissions array for frontend sharing.
     */
    public function getPermissionsArray(): array
    {
        if ($this->isAdmin()) {
            return ['*'];
        }
        return $this->role ? ($this->role->permissions ?? []) : [];
    }
}
