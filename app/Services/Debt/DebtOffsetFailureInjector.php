<?php

namespace App\Services\Debt;

class DebtOffsetFailureInjector
{
    public function hit(string $point): void
    {
        // Intentionally empty in production; tests bind a throwing implementation.
    }
}
