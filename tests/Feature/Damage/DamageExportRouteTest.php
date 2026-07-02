<?php

namespace Tests\Feature\Damage;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DamageExportRouteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_damage_export_returns_csv_for_admin_user(): void
    {
        $admin = User::create([
            'name' => 'Damage Export Admin',
            'email' => 'damage-export-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('damages.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'filename="xuat_huy.csv"',
            (string) $response->headers->get('Content-Disposition')
        );
    }
}
