<?php

namespace App\Services;

use RuntimeException;

class PartnerAlreadyExistsException extends RuntimeException
{
    public function __construct(
        private readonly array $fieldErrors,
        private readonly ?array $existingPartner,
        private readonly ?string $suggestedAction = null,
    ) {
        parent::__construct('Đối tác đã tồn tại. Vui lòng chọn đối tác có sẵn để liên kết.');
    }

    public function errors(): array
    {
        return $this->fieldErrors;
    }

    public function existingPartner(): ?array
    {
        return $this->existingPartner;
    }

    public function suggestedAction(): ?string
    {
        return $this->suggestedAction;
    }
}
