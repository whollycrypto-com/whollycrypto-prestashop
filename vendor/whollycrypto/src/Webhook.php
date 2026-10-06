<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk;

use WhollyCrypto\PrestaShop\Sdk\Exception\InvalidSignatureException;
use WhollyCrypto\PrestaShop\Sdk\Internal\Validation;

/** Same signature protocol for per-invoice/store IPN and event webhooks. */
final class Webhook
{
    public static function verify(
        string $rawBody,
        ?string $signature,
        #[\SensitiveParameter]
        string $signingSecret,
        int $toleranceSeconds = 300,
        ?int $now = null
    ): bool {
        if ($signingSecret === '' || $toleranceSeconds < 0 || $toleranceSeconds > 86_400) {
            throw new \InvalidArgumentException('Use a nonempty signing secret and a 0–86400 second clock tolerance.');
        }
        if (strlen($rawBody) > 262_144 || $signature === null
            || !preg_match('/\At=(0|[1-9][0-9]{0,11}),v1=([0-9a-f]{64})\z/D', $signature, $match)) {
            return false;
        }
        $timestamp = (int) $match[1];
        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $match[1] . '.' . $rawBody, $signingSecret), $match[2]);
    }

    /**
     * Verify the exact bytes before decoding. Header names are case-insensitive.
     * Event/delivery headers are identifiers, not covered by the body signature.
     * Persist sequence/state and deduplication checks in your own application.
     *
     * @param array<string, string> $headers e.g. getallheaders(); not $_SERVER keys
     */
    public static function parse(
        string $rawBody,
        array $headers,
        #[\SensitiveParameter]
        string $signingSecret,
        int $toleranceSeconds = 300,
        ?int $now = null
    ): Notification {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new InvalidSignatureException('Notification has invalid headers.');
            }
            $key = strtolower($name);
            if (isset($normalized[$key]) && in_array($key, ['wholly-signature', 'wholly-event-id', 'wholly-delivery-id'], true)) {
                throw new InvalidSignatureException('Duplicate notification security header.');
            }
            $normalized[$key] = $value;
        }
        if (!self::verify($rawBody, $normalized['wholly-signature'] ?? null, $signingSecret, $toleranceSeconds, $now)) {
            throw new InvalidSignatureException('Invalid or expired notification signature.');
        }
        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $eventId = Validation::uuid($normalized['wholly-event-id'] ?? '');
            $deliveryId = Validation::uuid($normalized['wholly-delivery-id'] ?? '');
            if (!is_array($payload) || !is_string($payload['invoice_id'] ?? null)
                || !is_int($payload['sequence'] ?? null) || $payload['sequence'] < 1
                || !in_array($payload['status'] ?? null, ['new', 'processing', 'settled', 'expired', 'invalid', 'cancelled'], true)) {
                throw new \InvalidArgumentException('Invalid payload.');
            }
            Validation::uuid($payload['invoice_id']);
            if (array_key_exists('payload_version', $payload)) {
                if ($payload['payload_version'] !== 2
                    || !is_string($payload['event_id'] ?? null) || !is_string($payload['project_id'] ?? null) || !is_string($payload['store_id'] ?? null)
                    || Validation::uuid($payload['event_id'] ?? '') !== $eventId
                    || !in_array($payload['event_type'] ?? null, ['invoice.created', 'payment.received', 'invoice.processing', 'invoice.settled', 'invoice.expired', 'invoice.invalid', 'invoice.cancelled'], true)) {
                    throw new \InvalidArgumentException('Invalid versioned event.');
                }
                Validation::uuid($payload['project_id'] ?? '');
                Validation::uuid($payload['store_id'] ?? '');
                $eventId = Validation::uuid($payload['event_id']); // Authenticated body identity, not a transport header.
            }
        } catch (\JsonException | \InvalidArgumentException $error) {
            throw new InvalidSignatureException('Signed notification has invalid identifiers or payload.');
        }
        return new Notification($eventId, $deliveryId, $payload);
    }
}
