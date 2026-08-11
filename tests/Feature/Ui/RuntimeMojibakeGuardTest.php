<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

class RuntimeMojibakeGuardTest extends TestCase
{
    public function test_detector_catches_known_mojibake_signature_fixtures(): void
    {
        foreach (self::knownMojibakeFixtures() as $name => $fixture) {
            $this->assertTrue(
                self::containsMojibake($fixture),
                "Expected fixture {$name} to be detected as Mojibake."
            );
        }
    }

    public function test_detector_does_not_flag_valid_vietnamese_utf8_strings(): void
    {
        $falsePositives = [];

        foreach (self::validVietnameseFixtures() as $name => $fixture) {
            if (self::containsMojibake($fixture)) {
                $falsePositives[$name] = $fixture;
            }
        }

        $this->assertSame([], $falsePositives);
    }

    public function test_runtime_source_has_no_unapproved_mojibake_signatures(): void
    {
        $roots = [
            base_path('app'),
            base_path('resources'),
            base_path('routes'),
            base_path('config'),
            base_path('database/seeders'),
        ];

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
                    if (! self::containsMojibake($line)) {
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
        // This exact decoder line intentionally contains the signatures it repairs/detects.
        if ($relativePath === 'app/Support/Status/BusinessStatus.php'
            && trim($line) === self::businessStatusDetectorLine()) {
            return true;
        }

        // Keep only this exact legacy DB value in the query so historical rows remain readable.
        return $relativePath === 'app/Services/Debt/Source/CustomerDebtDomainEventSource.php'
            && str_contains($line, "'".self::legacySupplierTargetValue()."'");
    }

    private static function containsMojibake(string $value): bool
    {
        if (preg_match(self::mojibakePattern(), $value) === 1) {
            return true;
        }

        foreach (self::literalSignatures() as $signature) {
            if (str_contains($value, $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function mojibakePattern(): string
    {
        return '/(?:\x{00C3}[\x{00A0}-\x{00BF}\x{0192}\x{201A}\x{201E}\x{2020}]|'
            .'\x{00C4}[\x{0080}-\x{00BF}\x{0192}\x{2018}\x{201A}\x{201E}\x{2020}]|'
            .'\x{00C2}[\x{00A0}-\x{00BF}]|'
            .'\x{00E1}[\x{00BA}\x{00BB}]|'
            .'\x{00E2}(?:\x{0027}\x{00AB}|\x{2019}|\x{201A}\x{20AC}|[\x{0080}-\x{009F}])|'
            .'\x{00EF}\x{00BF}\x{00BD})/u';
    }

    private static function literalSignatures(): array
    {
        return [
            // U+00C4 family: the production-shaped `Ä„â€˜` and `Ä„Â` forms.
            "\u{00C4}\u{201E}\u{00E2}\u{20AC}\u{02DC}",
            "\u{00C4}\u{201E}\u{00C2}\u{0090}",
            "\u{00C4}\u{2018}",
            // Keep the corresponding U+00C3 family explicit as well.
            "\u{00C3}\u{201E}\u{00E2}\u{20AC}\u{02DC}",
            "\u{00C3}\u{201E}\u{00C2}\u{0090}",
            "\u{00EF}\u{00BF}\u{00BD}",
            "\u{FFFD}",
        ];
    }

    private static function knownMojibakeFixtures(): array
    {
        return [
            'a-diaeresis-left-quote' => "26.900.000 \u{00C4}\u{2018}",
            'a-diaeresis-d-direct' => "26.900.000 \u{00C4}\u{0090}",
            'a-diaeresis-quote' => "26.900.000 \u{00C4}\u{201E}\u{00E2}\u{20AC}\u{02DC}",
            'a-diaeresis-d' => "26.900.000 \u{00C4}\u{201E}\u{00C2}\u{0090}",
            'cancel-label' => "H\u{00C3}\u{00A1}\u{00C2}\u{00BB}\u{00C2}\u{00A7}y",
            'information-label-direct' => "Th\u{00C3}\u{00B4}ng tin",
            'information-label' => "Th\u{00C3}\u{0192}\u{00C2}\u{00B4}ng tin",
            'debt-label-direct' => "C\u{00C3}\u{00B4}ng nợ",
            'debt-label' => "C\u{00C3}\u{0192}\u{00C2}\u{00B4}ng n\u{00E1}\u{00BB}\u{00A3}",
            'typographic-quote-direct' => "\u{00E2}\u{2019}",
            'typographic-quote' => "\u{00C3}\u{00A2}\u{00E2}\u{201A}\u{00AC}\u{00E2}\u{201E}\u{00A2}",
            'replacement-character' => "\u{00EF}\u{00BF}\u{00BD}",
            'replacement-character-direct' => "\u{FFFD}",
        ];
    }

    private static function validVietnameseFixtures(): array
    {
        return [
            'cancel-label' => 'Hủy',
            'total-label' => 'Tổng tiền',
            'formatted-money' => '26.900.000đ',
            'debt-label' => 'Công nợ',
            'information-label' => 'Thông tin',
            'cancelled-label' => 'Đã hủy',
            'supplier-label' => 'Nhà cung cấp',
        ];
    }

    private static function businessStatusDetectorLine(): string
    {
        return "if (preg_match('/(?:\u{00C3}|\u{00C4}|\u{00C2}|\u{00E1}\u{00BA}|\u{00E1}\u{00BB})/u', \$repaired) !== 1) {";
    }

    private static function legacySupplierTargetValue(): string
    {
        return "Nh\u{00C3}\u{00A0} cung c\u{00E1}\u{00BA}\u{00A5}p";
    }
}
