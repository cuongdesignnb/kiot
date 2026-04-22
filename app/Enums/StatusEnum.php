<?php

namespace App\Enums;

/**
 * Base contract for domain status enums so controllers can ship their option
 * lists to the frontend without hardcoding labels in Vue components.
 *
 * Implementations expose ::options() returning [{value,label,color?}, ...].
 */
interface StatusEnum
{
    /** @return array<int, array{value:string,label:string,color?:string}> */
    public static function options(): array;
}
