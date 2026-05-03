<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Step 22.2E: AJAX customer search endpoint cho Orders/Create.
 *
 * Trước đây Orders/Create render toàn bộ Customer::all() thành dropdown,
 * không filter theo input → user gõ tên không ra kết quả.
 *
 * Endpoint /api/customers/search trả JSON, schema-tolerant (is_customer/status).
 */
class CustomerSearchApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin 22.2E',
            'email'    => 'admin-22-2e-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function makeCustomer(array $attrs = []): Customer
    {
        $defaults = [
            'code'        => 'KH22E-' . uniqid(),
            'name'        => 'Nguyễn Văn ' . uniqid(),
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-22-2e-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ];
        if (Schema::hasColumn('customers', 'is_customer')) {
            $defaults['is_customer'] = true;
        }
        return Customer::create(array_merge($defaults, $attrs));
    }

    public function test_api_customer_search_returns_matches_by_name_phone_code(): void
    {
        $a = $this->makeCustomer(['name' => 'Trần Thị Hằng', 'phone' => '0911223344', 'code' => 'KH-FIND-AAA']);
        $this->makeCustomer(['name' => 'Người Khác', 'phone' => '0900000000']);

        // by name fragment
        $resp = $this->actingAs($this->admin)
            ->getJson('/api/customers/search?search=' . urlencode('Hằng'));
        $resp->assertOk();
        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertContains($a->id, $ids);

        // by phone fragment
        $resp = $this->actingAs($this->admin)
            ->getJson('/api/customers/search?search=' . urlencode('1122'));
        $resp->assertOk();
        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertContains($a->id, $ids);

        // by code
        $resp = $this->actingAs($this->admin)
            ->getJson('/api/customers/search?search=' . urlencode('KH-FIND-AAA'));
        $resp->assertOk();
        $resp->assertJsonFragment(['id' => (int) $a->id]);

        // shape check
        $first = $resp->json()[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('phone', $first);
        $this->assertArrayHasKey('display_label', $first);
    }

    public function test_api_customer_search_returns_empty_for_blank(): void
    {
        $this->makeCustomer();
        $resp = $this->actingAs($this->admin)->getJson('/api/customers/search?search=');
        $resp->assertOk();
        $this->assertSame([], $resp->json());

        $resp = $this->actingAs($this->admin)->getJson('/api/customers/search?search=' . urlencode('   '));
        $resp->assertOk();
        $this->assertSame([], $resp->json());
    }

    public function test_api_customer_search_does_not_return_inactive_if_status_exists(): void
    {
        if (! Schema::hasColumn('customers', 'status')) {
            $this->markTestSkipped('customers.status column not present.');
        }

        $active   = $this->makeCustomer(['name' => 'Active 22E ' . uniqid(), 'status' => 'active']);
        $inactive = $this->makeCustomer(['name' => 'Inactive 22E ' . uniqid(), 'status' => 'inactive']);

        $resp = $this->actingAs($this->admin)
            ->getJson('/api/customers/search?search=' . urlencode('22E'));
        $resp->assertOk();
        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_api_customer_search_requires_auth(): void
    {
        $resp = $this->get('/api/customers/search?search=test');
        $this->assertContains($resp->getStatusCode(), [302, 401, 419]);
    }
}
