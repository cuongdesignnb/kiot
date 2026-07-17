<?php

namespace App\Http\Requests\DebtOffsets;

final class ReverseDebtOffsetRequest extends DebtOffsetWorkflowRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason', ''))]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'version_token' => ['required', 'string', 'size:64'],
        ];
    }
}
