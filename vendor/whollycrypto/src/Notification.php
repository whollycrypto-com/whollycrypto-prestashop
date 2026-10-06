<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk;

/**
 * @property-read string $eventId
 * @property-read string $deliveryId
 * @property-read array<string, mixed> $payload
 */
final class Notification implements \JsonSerializable
{
    use Internal\RejectDynamicProperties;

    private const READABLE_PROPERTIES = ['eventId', 'deliveryId', 'payload'];
    private string $eventId;
    private string $deliveryId;
    private array $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(string $eventId, string $deliveryId, array $payload)
    {
        $this->initializeImmutable();
        $this->eventId = $eventId;
        $this->deliveryId = $deliveryId;
        $this->payload = $payload;
    }

    public function invoiceId(): string
    {
        return $this->payload['invoice_id'];
    }

    public function status(): string
    {
        return $this->payload['status'];
    }

    public function sequence(): int
    {
        return $this->payload['sequence'];
    }
}
