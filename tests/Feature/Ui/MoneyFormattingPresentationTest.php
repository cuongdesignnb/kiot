<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

class MoneyFormattingPresentationTest extends TestCase
{
    public function test_shared_currency_formatter_is_not_suffixed_again_in_frontend_source(): void
    {
        $pattern = '/(?:formatCurrency|formatVND)\s*\([^;\r\n]*\)\s*[đ₫]/u';
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/js'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! preg_match('/\.(js|vue)$/', $file->getFilename())) {
                continue;
            }

            $contents = file_get_contents($file->getPathname()) ?: '';
            if (preg_match($pattern, $contents)) {
                $violations[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        $this->assertSame([], $violations, "Currency suffix appended after shared formatter:\n".implode("\n", $violations));
    }
}
