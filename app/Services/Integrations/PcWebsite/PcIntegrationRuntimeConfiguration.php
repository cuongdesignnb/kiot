<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;

class PcIntegrationRuntimeConfiguration
{
    public function __construct(private readonly PcIntegrationCredentialResolver $resolver) {}

    public function current(): RuntimePcIntegrationConfig
    {
        $request = request();
        $runtime = $request?->attributes->get('pc_integration_runtime');
        if ($runtime instanceof RuntimePcIntegrationConfig) {
            return $runtime;
        }

        $clientId = $request?->attributes->get('pc_integration_client');
        $runtime = $this->resolver->resolve(is_string($clientId) ? $clientId : null);
        if (! $runtime || ! $runtime->isComplete()) {
            throw new PcIntegrationException('INTEGRATION_NOT_CONFIGURED', 'Cấu hình tích hợp Website PC chưa hoàn chỉnh.', 503);
        }

        return $runtime;
    }
}
