<?php

namespace App\Http\Requests\DebtOffsets;

final class StoreDebtOffsetDraftRequest extends DebtOffsetWorkflowRequest
{
    public function rules(): array
    {
        return [
            'amount' => $this->exactMoneyRules(),
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
