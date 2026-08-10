<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

class RuntimeMojibakeGuardTest extends TestCase
{
    public function test_runtime_source_has_no_unapproved_mojibake_signatures(): void
    {
        $roots = [
            base_path('app'),
            base_path('resources'),
            base_path('routes'),
            base_path('config'),
            base_path('database/seeders'),
        ];

        $pattern = '/(?:\x{00C3}[\x{00A0}-\x{00BF}\x{0192}\x{201A}\x{201E}\x{2020}]|'
            .'\x{00C2}[\x{00A0}-\x{00BF}]|'
            .'\x{00E1}[\x{00BA}\x{00BB}]|'
            .'\x{00E2}(?:\x{0027}\x{00AB}|\x{201A}\x{20AC}|[\x{0080}-\x{009F}])|'
            .'\x{00EF}\x{00BF}\x{00BD})/u';

        $hits = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! preg_match('/\.(php|vue|js|blade\.php|json|css|html)$/', $file->getFilename())) {
                    continue;
                }

                $relative = trim(str_replace('\\', '/', str_replace(base_path(), '', $file->getPathname())), '/');
                $lines = preg_split('/\r?\n/', file_get_contents($file->getPathname()) ?: '');

                foreach ($lines as $lineNumber => $line) {
                    if (! preg_match($pattern, $line)) {
                        continue;
                    }

                    if ($this->isAllowlistedInternalHit($relative, $line)) {
                        continue;
                    }

                    $hits[] = $relative.':'.($lineNumber + 1);
                }
            }
        }

        $this->assertSame([], $hits, "Unapproved runtime Mojibake hits:\n".implode("\n", $hits));
    }

    private function isAllowlistedInternalHit(string $relativePath, string $line): bool
    {
        // This detector intentionally contains the signatures it repairs/detects.
        if ($relativePath === 'app/Support/Status/BusinessStatus.php' && str_contains($line, 'preg_match')) {
            return true;
        }

        // Keep the legacy DB value in the query so existing historical rows remain readable.
        return $relativePath === 'app/Services/Debt/Source/CustomerDebtDomainEventSource.php'
            && str_contains($line, 'target_type')
            && str_contains($line, 'whereIn');
    }
}
