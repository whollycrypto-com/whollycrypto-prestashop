<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk\Internal;

/** @internal Keep compatibility helpers local; never define global polyfills. */
final class Compat
{
    public static function isList(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    public static function startsWith(string $value, string $prefix): bool
    {
        return $prefix === '' || strncmp($value, $prefix, strlen($prefix)) === 0;
    }

    public static function contains(string $value, string $needle): bool
    {
        return $needle === '' || strpos($value, $needle) !== false;
    }
}
