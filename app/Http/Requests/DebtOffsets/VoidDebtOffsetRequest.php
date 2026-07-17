<?php

namespace App\Http\Requests\DebtOffsets;

final class VoidDebtOffsetRequest extends DebtOffsetWorkflowRequest
{
    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');
        $this->merge(['reason' => $reason === null ? null : trim((string) $reason)]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
            'version_token' => ['required', 'string', 'size:64'],
        ];
    }
}
