<?php
declare(strict_types=1);

namespace WhollyCrypto\PrestaShop;

use WhollyCrypto\PrestaShop\Sdk\Client;
use WhollyCrypto\PrestaShop\Sdk\Options;
use WhollyCrypto\PrestaShop\Sdk\Webhook;
use WhollyCrypto\PrestaShop\Sdk\Http\TransportInterface;

/** Only the public merchant API is used. No wallet keys or blockchain calls. */
final class Protocol
{
    public static function decimal(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{1,30}(?:\.[0-9]{1,30})?\z/D', $value)) {
            throw new \InvalidArgumentException('Expected an unsigned decimal string.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        return (ltrim($whole, '0') ?: '0') . ($fraction === '' ? '' : '.' . $fraction);
    }

    /** Convert a platform's calculated fiat total at its currency precision only. */
    public static function fiat(float $value, int $precision): string
    {
        if (!is_finite($value) || $value <= 0 || $value > 1_000_000_000 || $precision < 0 || $precision > 8) {
            throw new \InvalidArgumentException('Unsupported order total or currency precision.');
        }
        return self::decimal(number_format($value, $precision, '.', ''));
    }

    public static function uuid(string $value): string
    {
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di', $value)) {
            throw new \InvalidArgumentException('Use the Project and Store API UUIDs, not their readable identifiers.');
        }
        return strtolower($value);
    }

    public static function https(string $value, bool $origin = false): string
    {
        $p = parse_url($value);
        if (!filter_var($value, FILTER_VALIDATE_URL) || !$p || ($p['scheme'] ?? '') !== 'https'
            || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $value)
            || ($origin && (isset($p['query']) || !in_array($p['path'] ?? '', ['', '/'], true)))) {
            throw new \InvalidArgumentException('Use HTTPS without credentials. API and checkout origins must not contain paths or queries.');
        }
        return $origin ? rtrim($value, '/') : $value;
    }

    public static function settings(array $input): array
    {
        $result = [];
        foreach (['api_url', 'pay_url'] as $key) {
            $result[$key] = self::https(trim((string) ($input[$key] ?? '')), true);
        }
        foreach (['project_id', 'store_id'] as $key) {
            $result[$key] = self::uuid(trim((string) ($input[$key] ?? '')));
        }
        foreach (['api_key', 'ipn_secret'] as $key) {
            $result[$key] = trim((string) ($input[$key] ?? ''));
            if (!preg_match('/\A[\x21-\x7e]{16,4096}\z/D', $result[$key])) {
                throw new \InvalidArgumentException('An API credential and store IPN signing secret are required.');
            }
        }
        return $result;
    }

    public static function binding(array $s): string
    {
        return hash('sha256', implode('|', [$s['api_url'], $s['pay_url'], $s['project_id'], $s['store_id']]));
    }

    public static function client(array $s, ?TransportInterface $transport = null): Client
    {
        return new Client($s['api_url'], $s['api_key'], new Options(
            timeoutSeconds: 8, connectTimeoutSeconds: 3, maxRetries: 0, maxResponseBytes: 2_097_152
        ), $transport);
    }

    /** Persist the returned record before calling fetch(). Never regenerate its key on timeout. */
    public static function attempt(array $s, int $orderId, string $reference, string $amount, string $currency, array $options): array
    {
        $amount = self::decimal($amount);
        if ($orderId < 1 || $amount === '0' || !preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new \InvalidArgumentException('A positive fiat order is required.');
        }
        foreach (['ipn_url', 'redirect_url', 'cancel_url'] as $key) {
            self::https((string) ($options[$key] ?? ''));
        }
        return [
            'order_id' => $orderId, 'attempt_key' => Client::newIdempotencyKey(),
            'binding' => self::binding($s), 'credential_hash' => hash('sha256', $s['api_key']),
            'project_id' => $s['project_id'], 'store_id' => $s['store_id'],
            'payload' => array_replace($options, ['amount' => $amount, 'currency' => $currency, 'order_id' => $reference]),
            'invoice_id' => null, 'sequence' => 0, 'status' => 'new', 'amount_status' => 'none',
            'review' => false, 'checkout_url' => '', 'applied' => '',
        ];
    }

    public static function fetch(array $s, array $a, ?TransportInterface $transport = null): array
    {
        if (!hash_equals($a['binding'], self::binding($s))) {
            throw new \RuntimeException('Restore the original connection to reconcile this order.');
        }
        $client = self::client($s, $transport);
        if (!$a['invoice_id']) {
            if (!hash_equals($a['credential_hash'], hash('sha256', $s['api_key']))) {
                throw new \RuntimeException('An uncertain invoice creation used a different API credential.');
            }
            $result = $client->createInvoice($a['project_id'], $a['store_id'], $a['payload'], $a['attempt_key']);
        } else {
            $result = $client->getInvoice($a['project_id'], $a['invoice_id']);
        }
        $invoice = $result['data'] ?? null;
        if (!is_array($invoice)) {
            throw new \UnexpectedValueException('Invalid invoice response.');
        }
        self::match($a, $invoice);
        if (!in_array($invoice['status'] ?? '', ['new', 'processing', 'settled', 'expired', 'invalid', 'cancelled'], true)
            || !in_array($invoice['amount_status'] ?? '', ['none', 'partial', 'paid', 'overpaid'], true)
            || !is_int($invoice['sequence'] ?? null) || $invoice['sequence'] < max(1, $a['sequence'])
            || !is_bool($invoice['requires_review'] ?? null)) {
            throw new \UnexpectedValueException('Invalid or stale invoice state.');
        }
        $url = self::https((string) ($result['links']['checkout'] ?? ''));
        if ($url !== $s['pay_url'] . '/invoice/' . $invoice['invoice_id']) {
            throw new \UnexpectedValueException('Checkout origin differs from the configured checkout URL.');
        }
        return array_replace($a, [
            'invoice_id' => $invoice['invoice_id'], 'status' => $invoice['status'],
            'amount_status' => $invoice['amount_status'], 'sequence' => $invoice['sequence'],
            'review' => $invoice['requires_review'] || (($invoice['timing_status'] ?? 'on_time') !== 'on_time'),
            'checkout_url' => $url,
        ]);
    }

    public static function match(array $a, array $invoice): void
    {
        self::uuid((string) ($invoice['invoice_id'] ?? ''));
        if (($a['invoice_id'] && $invoice['invoice_id'] !== $a['invoice_id'])
            || ($invoice['project_id'] ?? null) !== $a['project_id']
            || ($invoice['store_id'] ?? null) !== $a['store_id']
            || ($invoice['order_id'] ?? null) !== $a['payload']['order_id']
            || ($invoice['currency'] ?? null) !== $a['payload']['currency']
            || self::decimal($invoice['amount'] ?? null) !== $a['payload']['amount']) {
            throw new \UnexpectedValueException('Invoice does not match the saved order, project, store, amount or currency.');
        }
    }

    /** Validate the signed event; fulfillment still requires a fresh authoritative API read. */
    public static function notification(array $a, string $raw, array $headers, string $secret): array
    {
        $p = Webhook::parse($raw, $headers, $secret)->payload;
        if (($p['payload_version'] ?? null) !== 2) {
            throw new \UnexpectedValueException('Payload version 2 is required.');
        }
        self::match($a, $p);
        return $p;
    }

    public static function decision(array $a): string
    {
        if ($a['review'] || $a['status'] === 'invalid') {
            return 'review';
        }
        if ($a['status'] === 'settled' && in_array($a['amount_status'], ['paid', 'overpaid'], true)) {
            return 'paid';
        }
        if (in_array($a['status'], ['expired', 'cancelled'], true)) {
            return $a['amount_status'] === 'none' ? 'expired' : 'review';
        }
        return 'pending';
    }

    public static function error(\Throwable $e): string
    {
        if ($e instanceof Sdk\Exception\ApiException) {
            return 'Wholly Crypto API HTTP ' . $e->statusCode . '. Check the store configuration and API permissions.';
        }
        if ($e instanceof Sdk\Exception\TransportException) {
            return 'Wholly Crypto connection failed. Retry the same order; do not create another invoice manually.';
        }
        return 'Wholly Crypto could not safely process this order. Check its invoice, connection and order total.';
    }
}
