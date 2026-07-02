<?php

namespace Tests\Feature\Damage;

use App\Models\Branch;
use App\Models\Damage;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DamageIndexFilterTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Damage Filter Admin',
            'email' => 'damage-filter-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $this->branch = Branch::firstOrCreate(['name' => 'Damage Filter Branch'], ['address' => 'Test']);
    }

    public function test_damage_index_filters_creator_and_destroyer_exactly(): void
    {
        Damage::create([
            'code' => 'XH-FILTER-A',
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'created_by_name' => 'Nguyễn A',
            'destroyed_by_name' => 'Nguyễn A',
            'destroyed_date' => now(),
            'total_qty' => 1,
            'total_value' => 1000,
        ]);

        Damage::create([
            'code' => 'XH-FILTER-B',
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'created_by_name' => 'Nguyễn AB',
            'destroyed_by_name' => 'Nguyễn AB',
            'destroyed_date' => now(),
            'total_qty' => 1,
            'total_value' => 1000,
        ]);

        $this->actingAs($this->admin)
            ->get('/damages?created_by_name=' . urlencode('Nguyễn A') . '&destroyed_by_name=' . urlencode('Nguyễn A'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('damages.total', 1)
                ->where('damages.data.0.code', 'XH-FILTER-A')
                ->where('filters.created_by_name', 'Nguyễn A')
                ->where('filters.destroyed_by_name', 'Nguyễn A')
            );
    }

    public function test_damage_index_exposes_employee_and_date_preset_filter_options(): void
    {
        Employee::create([
            'name' => 'Nhân Viên Xuất Hủy',
            'code' => 'NV-XH-FILTER',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/damages')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filterOptions.datePresets.0.value', 'all')
                ->where('filterOptions.creators', fn ($options) => collect($options)->contains('value', 'Nhân Viên Xuất Hủy'))
                ->where('filterOptions.destroyers', fn ($options) => collect($options)->contains('value', 'Nhân Viên Xuất Hủy'))
            );
    }
}
