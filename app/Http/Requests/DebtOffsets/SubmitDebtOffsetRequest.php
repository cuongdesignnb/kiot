<?php

namespace App\Http\Requests\DebtOffsets;

final class SubmitDebtOffsetRequest extends DebtOffsetWorkflowRequest
{
    public function rules(): array
    {
        return ['version_token' => ['required', 'string', 'size:64']];
    }
}
