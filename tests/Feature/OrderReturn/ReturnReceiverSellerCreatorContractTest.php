<?php

namespace Tests\Feature\OrderReturn;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderReturnCreationService;
use App\Support\Reports\SellerResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReturnReceiverSellerCreatorContractTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Return QA Admin',
            'email' => 'return-qa-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function employee(string $name, bool $active = true): Employee
    {
        return Employee::create([
            'code' => 'NV-'.uniqid(),
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'RET-'.uniqid(),
            'name' => 'Return contract product',
            'cost_price' => 10,
            'retail_price' => 20,
            'stock_quantity' => 1,
            'inventory_total_cost' => 10,
            'is_active' => true,
            'has_serial' => false,
            'category_id' => Category::firstOrCreate(['name' => 'Return contract category'])->id,
        ]);
    }

    private function createReturn(?int $receiverId = null): OrderReturn
    {
        $payload = [
            'subtotal' => 20,
            'discount' => 0,
            'fee' => 0,
            'total' => 20,
            'paid_to_customer' => 0,
            'items' => [[
                'product_id' => $this->product()->id,
                'qty' => 1,
                'price' => 20,
            ]],
        ];
        if ($receiverId !== null) {
            $payload['received_by_employee_id'] = $receiverId;
        }

        return app(OrderReturnCreationService::class)->create($payload, [
            'created_by_name' => 'Return creator snapshot',
        ]);
    }

    public function test_migration_adds_nullable_receiver_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('returns', 'received_by_employee_id'));
        $this->assertTrue(Schema::hasColumn('returns', 'received_by_name'));
    }

    public function test_new_return_persists_active_receiver_snapshot_without_seller_mutation(): void
    {
        $receiver = $this->employee('Receiver C');
        $return = $this->createReturn($receiver->id);

        $this->assertSame($receiver->id, $return->received_by_employee_id);
        $this->assertSame('Receiver C', $return->received_by_name);
        $this->assertSame('Return creator snapshot', $return->created_by_name);
        $this->assertNull($return->seller_name);
    }

    public function test_inactive_receiver_is_rejected_before_return_mutation(): void
    {
        $inactive = $this->employee('Inactive Receiver', false);
        $before = OrderReturn::count();

        $this->expectException(ValidationException::class);
        try {
            $this->createReturn($inactive->id);
        } finally {
            $this->assertSame($before, OrderReturn::count());
        }
    }

    public function test_receiver_endpoint_updates_only_receiver_fields_and_logs_change(): void
    {
        $admin = $this->admin();
        $first = $this->employee('Receiver C');
        $second = $this->employee('Receiver D');
        $return = $this->createReturn($first->id);
        $before = [
            'invoice_id' => $return->invoice_id,
            'customer_id' => $return->customer_id,
            'created_by_name' => $return->created_by_name,
            'seller_name' => $return->seller_name,
            'total' => (float) $return->total,
            'paid_to_customer' => (float) $return->paid_to_customer,
        ];

        $response = $this->actingAs($admin)->patchJson(route('returns.update-receiver', $return), [
            'received_by_employee_id' => $second->id,
        ]);

        $response->assertOk()->assertJsonPath('return.received_by_employee_id', $second->id);
        $return->refresh();
        $this->assertSame('Receiver D', $return->received_by_name);
        $this->assertSame($before['invoice_id'], $return->invoice_id);
        $this->assertSame($before['customer_id'], $return->customer_id);
        $this->assertSame($before['created_by_name'], $return->created_by_name);
        $this->assertSame($before['seller_name'], $return->seller_name);
        $this->assertSame($before['total'], (float) $return->total);
        $this->assertSame($before['paid_to_customer'], (float) $return->paid_to_customer);
        $this->assertTrue(ActivityLog::where('action', ActivityLog::ACTION_RETURN_RECEIVER_UPDATE)
            ->where('subject_id', $return->id)->exists());
    }

    public function test_original_seller_comes_from_source_invoice_not_return_creator(): void
    {
        $seller = $this->employee('Source seller B');
        $invoice = Invoice::create([
            'code' => 'HD-RETURN-'.uniqid(),
            'created_by' => $seller->id,
            'seller_name' => 'Source seller snapshot',
            'created_by_name' => 'Creator A',
            'subtotal' => 0,
            'total' => 0,
            'status' => 'Hoàn thành',
        ]);
        $return = OrderReturn::create([
            'code' => 'TH-RETURN-'.uniqid(),
            'invoice_id' => $invoice->id,
            'created_by_name' => 'Creator A',
            'subtotal' => 0,
            'total' => 0,
        ]);

        $this->assertSame('Source seller B', app(SellerResolver::class)->displayNameForInvoice($return->invoice));
        $this->assertNotSame($return->created_by_name, app(SellerResolver::class)->displayNameForInvoice($return->invoice));
    }
}
