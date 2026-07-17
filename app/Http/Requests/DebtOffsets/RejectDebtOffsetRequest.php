<?php

namespace App\Http\Requests\DebtOffsets;

final class RejectDebtOffsetRequest extends DebtOffsetWorkflowRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['rejection_reason' => trim((string) $this->input('rejection_reason', ''))]);
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
            'version_token' => ['required', 'string', 'size:64'],
        ];
    }
}
