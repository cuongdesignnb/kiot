<?php

namespace App\Http\Requests\DebtOffsets;

final class UpdateDebtOffsetDraftRequest extends DebtOffsetWorkflowRequest
{
    public function rules(): array
    {
        return [
            'amount' => $this->exactMoneyRules(),
            'note' => ['nullable', 'string', 'max:1000'],
            'version_token' => ['required', 'string', 'size:64'],
        ];
    }
}
