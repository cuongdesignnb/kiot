<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;

class SecureIntegrationUrl
{
    public function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || ! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new PcIntegrationException('INVALID_WEBSITE_URL', 'Website URL không hợp lệ.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new PcIntegrationException('INVALID_WEBSITE_URL', 'Website URL không được chứa credentials, query hoặc fragment.');
        }
        if ((string) config('app.env') === 'production' && $scheme !== 'https') {
            throw new PcIntegrationException('HTTPS_REQUIRED', 'Kết nối production bắt buộc sử dụng HTTPS.');
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = isset($parts['path']) ? '/'.ltrim((string) $parts['path'], '/') : '';
        $path = $path === '/' ? '' : rtrim($path, '/');

        return "{$scheme}://{$host}{$port}{$path}";
    }
}
