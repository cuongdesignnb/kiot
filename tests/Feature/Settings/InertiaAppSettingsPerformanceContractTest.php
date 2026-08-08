<?php

namespace Tests\Feature\Settings;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InertiaAppSettingsPerformanceContractTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'App Settings N+1 QA',
            'email' => 'app-settings-n-plus-one-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $this->seedSettings();
    }

    public function test_setting_get_preserves_typed_value_contracts_and_missing_defaults(): void
    {
        foreach ($this->expectedSettings() as $key => $expected) {
            self::assertSame($expected, Setting::get($key));
            self::assertSame($expected, Setting::query()->where('key', $key)->firstOrFail()->resolvedValue());
        }

        self::assertSame('fallback', Setting::get('missing_setting', 'fallback'));
        self::assertNull(Setting::get('missing_setting_without_default'));
    }

    public function test_full_inertia_request_shares_a_complete_typed_map_with_bounded_settings_queries(): void
    {
        $queries = [];
        $allQueryCount = 0;
        $settingsQueryTime = 0.0;
        DB::listen(function ($query) use (&$queries, &$allQueryCount, &$settingsQueryTime): void {
            $allQueryCount++;

            if ($this->isSettingsQuery($query->sql)) {
                $queries[] = $query->sql;
                $settingsQueryTime += (float) $query->time;
            }
        });

        $startedAt = hrtime(true);
        $response = $this->actingAs($this->admin)->get('/', $this->inertiaHeaders());
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        $response->assertOk();
        $sharedSettings = $response->json('props.app_settings');

        // JSON transport normalizes an integer-like float (42.0) to 42; the
        // direct Setting::get/resolvedValue assertions cover the PHP type.
        self::assertEquals($this->expectedSettings(), $sharedSettings);
        fwrite(STDOUT, sprintf(
            "SETTINGS_COUNT=%d SETTINGS_QUERY_COUNT=%d APP_SETTINGS_ELAPSED_MS=%.3f APP_SETTINGS_DB_MS=%.3f FULL_INERTIA_REQUEST_MS=%.3f FULL_INERTIA_QUERY_COUNT=%d\n",
            count($sharedSettings),
            count($queries),
            $elapsedMs,
            $settingsQueryTime,
            $elapsedMs,
            $allQueryCount
        ));
        self::assertLessThanOrEqual(2, count($queries), implode("\n", $queries));
    }

    public function test_partial_purchases_reload_does_not_evaluate_app_settings(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if ($this->isSettingsQuery($query->sql)) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->actingAs($this->admin)->get('/purchases?date_filter=all', [
            ...$this->inertiaHeaders(),
            'X-Inertia-Partial-Component' => 'Purchases/Index',
            'X-Inertia-Partial-Data' => 'purchases,summary,filters',
        ]);

        $response->assertOk();
        self::assertCount(0, $queries, implode("\n", $queries));

        fwrite(STDOUT, sprintf("PARTIAL_SETTINGS_QUERY_COUNT=%d\n", count($queries)));
    }

    public function test_global_inertia_pages_receive_the_same_settings_map(): void
    {
        foreach (['/', '/purchases', '/suppliers', '/pos', '/orders'] as $path) {
            $response = $this->actingAs($this->admin)->get($path, $this->inertiaHeaders());

            $response->assertOk();
            self::assertEquals($this->expectedSettings(), $response->json('props.app_settings'), $path);
        }
    }

    private function seedSettings(): void
    {
        Setting::query()->delete();

        $settings = [
            ['key' => 'perf_string', 'value' => 'hello', 'type' => 'string'],
            ['key' => 'perf_boolean_true', 'value' => '1', 'type' => 'boolean'],
            ['key' => 'perf_boolean_false', 'value' => '0', 'type' => 'boolean'],
            ['key' => 'perf_number_integer', 'value' => '42', 'type' => 'number'],
            ['key' => 'perf_number_decimal', 'value' => '3.14', 'type' => 'number'],
            ['key' => 'perf_json_object', 'value' => json_encode(['a' => 1, 'enabled' => true]), 'type' => 'json'],
            ['key' => 'perf_json_array', 'value' => json_encode([1, 'two', false]), 'type' => 'json'],
        ];

        for ($i = count($settings) + 1; $i <= 25; $i++) {
            $settings[] = [
                'key' => sprintf('perf_filler_%02d', $i),
                'value' => 'value-'.$i,
                'type' => 'string',
            ];
        }

        foreach ($settings as $setting) {
            Setting::query()->create($setting + ['group' => 'performance']);
        }
    }

    private function expectedSettings(): array
    {
        $settings = [
            'perf_string' => 'hello',
            'perf_boolean_true' => true,
            'perf_boolean_false' => false,
            'perf_number_integer' => 42.0,
            'perf_number_decimal' => 3.14,
            'perf_json_object' => ['a' => 1, 'enabled' => true],
            'perf_json_array' => [1, 'two', false],
        ];

        for ($i = count($settings) + 1; $i <= 25; $i++) {
            $settings[sprintf('perf_filler_%02d', $i)] = 'value-'.$i;
        }

        return $settings;
    }

    private function inertiaHeaders(): array
    {
        $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

        return [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Inertia-Version' => $version ?? '',
        ];
    }

    private function isSettingsQuery(string $sql): bool
    {
        $sql = strtolower($sql);

        return str_contains($sql, 'from `settings`')
            || str_contains($sql, 'from "settings"')
            || str_contains($sql, "table_name = 'settings'")
            || (str_contains($sql, 'sqlite_master') && str_contains($sql, 'settings'));
    }
}
