<?php

namespace Tests\Feature\CashFlows;

use App\Models\CashFlow;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CashFlowEditCategoryTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::factory()->create(['role_id' => null]);
    }

    private function createPaymentCashFlow(array $overrides = []): CashFlow
    {
        return CashFlow::create(array_merge([
            'code' => 'PC-EDIT-CAT-' . uniqid(),
            'type' => 'payment',
            'amount' => 250000,
            'time' => Carbon::now(),
            'category' => 'Chi khác',
            'target_type' => 'Khác',
            'target_name' => 'Người nhận test',
            'accounting_result' => true,
            'payment_method' => 'cash',
            'bank_account_id' => null,
            'description' => 'Test đổi loại chi',
            'status' => 'active',
        ], $overrides));
    }

    private function createReceiptCashFlow(array $overrides = []): CashFlow
    {
        return CashFlow::create(array_merge([
            'code' => 'PT-EDIT-CAT-' . uniqid(),
            'type' => 'receipt',
            'amount' => 250000,
            'time' => Carbon::now(),
            'category' => 'Thu khác',
            'target_type' => 'Khác',
            'target_name' => 'Người nộp test',
            'accounting_result' => true,
            'payment_method' => 'cash',
            'bank_account_id' => null,
            'description' => 'Test loại thu',
            'status' => 'active',
        ], $overrides));
    }

    public function test_index_splits_saved_categories_by_cashflow_type_and_keeps_compat_options(): void
    {
        $receiptCategory = 'ReceiptOnly-' . uniqid();
        $paymentCategory = 'PaymentOnly-' . uniqid();

        $this->createReceiptCashFlow(['category' => $receiptCategory]);
        $this->createPaymentCashFlow(['category' => $paymentCategory]);

        $response = $this->actingAs($this->admin())
            ->get(route('cash_flows.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('savedReceiptCategories', function ($categories) use ($receiptCategory, $paymentCategory) {
                $values = collect($categories)->all();

                return in_array($receiptCategory, $values, true)
                    && !in_array($paymentCategory, $values, true);
            })
            ->where('savedPaymentCategories', function ($categories) use ($receiptCategory, $paymentCategory) {
                $values = collect($categories)->all();

                return in_array($paymentCategory, $values, true)
                    && !in_array($receiptCategory, $values, true);
            })
            ->where('filterOptions.categories', function ($options) use ($receiptCategory, $paymentCategory) {
                $values = collect($options)->pluck('value')->all();

                return in_array($receiptCategory, $values, true)
                    && in_array($paymentCategory, $values, true);
            })
            ->where('filterOptions.categoryGroups.receipt', function ($options) use ($receiptCategory, $paymentCategory) {
                $values = collect($options)->pluck('value')->all();
                $types = collect($options)->pluck('type')->unique()->all();

                return in_array($receiptCategory, $values, true)
                    && !in_array($paymentCategory, $values, true)
                    && $types === ['receipt'];
            })
            ->where('filterOptions.categoryGroups.payment', function ($options) use ($receiptCategory, $paymentCategory) {
                $values = collect($options)->pluck('value')->all();
                $types = collect($options)->pluck('type')->unique()->all();

                return in_array($paymentCategory, $values, true)
                    && !in_array($receiptCategory, $values, true)
                    && $types === ['payment'];
            })
        );
    }

    public function test_update_payment_cashflow_can_change_category_without_changing_type(): void
    {
        Setting::where('key', 'lock_date')->delete();
        $cashFlow = $this->createPaymentCashFlow();

        $this->actingAs($this->admin())
            ->put(route('cash_flows.update', $cashFlow), [
                'time' => Carbon::parse($cashFlow->time)->format('Y-m-d\TH:i'),
                'category' => 'Bảo hiểm',
                'target_type' => $cashFlow->target_type,
                'target_name' => $cashFlow->target_name,
                'amount' => $cashFlow->amount,
                'description' => $cashFlow->description,
                'accounting_result' => $cashFlow->accounting_result,
                'payment_method' => $cashFlow->payment_method,
                'bank_account_id' => null,
            ])
            ->assertRedirect();

        $cashFlow->refresh();

        $this->assertSame('Bảo hiểm', $cashFlow->category);
        $this->assertSame('payment', $cashFlow->type);
    }

    public function test_update_payment_cashflow_ignores_type_payload(): void
    {
        Setting::where('key', 'lock_date')->delete();
        $cashFlow = $this->createPaymentCashFlow();

        $this->actingAs($this->admin())
            ->put(route('cash_flows.update', $cashFlow), [
                'type' => 'receipt',
                'time' => Carbon::parse($cashFlow->time)->format('Y-m-d\TH:i'),
                'category' => 'Bảo hiểm',
                'target_type' => $cashFlow->target_type,
                'target_name' => $cashFlow->target_name,
                'amount' => $cashFlow->amount,
                'description' => $cashFlow->description,
                'accounting_result' => $cashFlow->accounting_result,
                'payment_method' => $cashFlow->payment_method,
                'bank_account_id' => null,
            ])
            ->assertRedirect();

        $cashFlow->refresh();

        $this->assertSame('Bảo hiểm', $cashFlow->category);
        $this->assertSame('payment', $cashFlow->type);
    }
}
