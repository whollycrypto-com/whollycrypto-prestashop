<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk\Internal;

use WhollyCrypto\PrestaShop\Sdk\Options;

/** @internal */
final class Validation
{
    public static function origin(string $url, Options $options): string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Use an absolute HTTPS origin without credentials, path, query or fragment.');
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if ($parts === false || $host === '' || filter_var($url, FILTER_VALIDATE_URL) === false
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || ($scheme !== 'https' && !($scheme === 'http' && $local && $options->allowInsecureLocalhost))
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new \InvalidArgumentException('Use an HTTPS origin such as https://api.example.com, without /v1 or credentials. HTTP is only allowed for explicitly enabled loopback tests.');
        }
        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function uuid(string $value): string
    {
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di', $value)) {
            throw new \InvalidArgumentException('Expected an API UUID, not a project/store identifier or order ID.');
        }
        return strtolower($value);
    }

    /** @param mixed $value */
    public static function decimal($value, string $field): void
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{1,48}(?:\.[0-9]{1,30})?\z/D', $value)) {
            throw new \InvalidArgumentException($field . ' must be a plain unsigned decimal string. Never use floats for money.');
        }
    }

    public static function object(array $value): \stdClass
    {
        if ($value !== [] && Compat::isList($value)) {
            throw new \InvalidArgumentException('Expected an associative JSON object, not a list.');
        }
        return (object) $value;
    }

    /**
     * Stable object ordering; list order, decimal strings and nulls remain intact.
     * @param mixed $value
     * @return mixed
     */
    public static function canonical($value, int $depth = 0)
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('JSON request nesting is too deep.');
        }
        if ($value instanceof \stdClass || (is_array($value) && !Compat::isList($value))) {
            $fields = (array) $value;
            ksort($fields, SORT_STRING);
            foreach ($fields as $key => $child) {
                $fields[$key] = self::canonical($child, $depth + 1);
            }
            return (object) $fields;
        }
        if (is_array($value)) {
            return array_map(static fn ($child) => self::canonical($child, $depth + 1), $value);
        }
        if (is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException('JSON values must be scalars, arrays or stdClass objects.');
        }
        return $value;
    }

    public static function query(array $query): string
    {
        foreach ($query as $key => $value) {
            if (!is_string($key) || !preg_match('/\A[a-z_]+\z/D', $key)
                || (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null)) {
                throw new \InvalidArgumentException('Query parameters must have named scalar values.');
            }
        }
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
