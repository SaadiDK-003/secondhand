<?php

declare(strict_types=1);

namespace App\Support;

final class PakistanMobile
{
    public static function normalize(string $value): string
    {
        $value = preg_replace('/[\s()-]+/', '', trim($value)) ?? '';

        if (preg_match('/^03[0-9]{9}$/', $value)) {
            return '+92'.substr($value, 1);
        }

        if (preg_match('/^923[0-9]{9}$/', $value)) {
            return '+'.$value;
        }

        return $value;
    }
}
