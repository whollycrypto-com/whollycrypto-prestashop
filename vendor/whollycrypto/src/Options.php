<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk;

/**
 * @property-read int $timeoutSeconds
 * @property-read int $connectTimeoutSeconds
 * @property-read int $maxRetries
 * @property-read int $maxRetryDelaySeconds
 * @property-read bool $allowInsecureLocalhost
 * @property-read int $maxResponseBytes
 */
final class Options implements \JsonSerializable
{
    use Internal\RejectDynamicProperties;

    private const READABLE_PROPERTIES = ['timeoutSeconds', 'connectTimeoutSeconds', 'maxRetries', 'maxRetryDelaySeconds', 'allowInsecureLocalhost', 'maxResponseBytes'];
    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;
    private int $maxRetries;
    private int $maxRetryDelaySeconds;
    private bool $allowInsecureLocalhost;
    private int $maxResponseBytes;

    public function __construct(
        int $timeoutSeconds = 20,
        int $connectTimeoutSeconds = 5,
        int $maxRetries = 0,
        int $maxRetryDelaySeconds = 60,
        bool $allowInsecureLocalhost = false,
        int $maxResponseBytes = 8_388_608
    ) {
        $this->initializeImmutable();
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120
            || $connectTimeoutSeconds < 1 || $connectTimeoutSeconds > $timeoutSeconds
            || $maxRetries < 0 || $maxRetries > 3
            || $maxRetryDelaySeconds < 0 || $maxRetryDelaySeconds > 60
            || $maxResponseBytes < 1024 || $maxResponseBytes > 67_108_864) {
            throw new \InvalidArgumentException('Invalid timeout, retry or response-size options.');
        }
        $this->timeoutSeconds = $timeoutSeconds;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->maxRetries = $maxRetries;
        $this->maxRetryDelaySeconds = $maxRetryDelaySeconds;
        $this->allowInsecureLocalhost = $allowInsecureLocalhost;
        $this->maxResponseBytes = $maxResponseBytes;
    }
}
