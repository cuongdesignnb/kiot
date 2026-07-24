<?php

namespace App\Services\Integrations\PcWebsite;

final readonly class RuntimePcIntegrationConfig
{
    public function __construct(
        public string $source,
        public ?int $integrationClientId,
        public bool $enabled,
        public string $clientId,
        private string $currentSecret,
        private ?string $previousSecret,
        private ?\DateTimeInterface $previousSecretExpiresAt,
        public ?int $defaultBranchId,
        public string $salesChannel,
        public int $timestampToleranceSeconds,
        public int $nonceTtlSeconds,
        public int $rateLimitPerMinute,
        public int $reservationTtlMinutes,
        public string $apiVersion = 'v1',
        public bool $revoked = false,
    ) {}

    public function isComplete(): bool
    {
        return ! $this->revoked
            && $this->clientId !== ''
            && $this->currentSecret !== ''
            && $this->defaultBranchId !== null;
    }

    /** @return list<string> */
    public function candidateSecrets(): array
    {
        $secrets = $this->currentSecret !== '' ? [$this->currentSecret] : [];

        if ($this->previousSecret !== null
            && $this->previousSecret !== ''
            && $this->previousSecretExpiresAt !== null
            && $this->previousSecretExpiresAt->getTimestamp() >= time()) {
            $secrets[] = $this->previousSecret;
        }

        return $secrets;
    }
}
