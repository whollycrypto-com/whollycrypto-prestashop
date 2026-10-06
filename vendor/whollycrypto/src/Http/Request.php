<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk\Http;

/**
 * @internal A custom transport receives credentials; only use trusted implementations.
 * @property-read string $method
 * @property-read string $url
 */
final class Request implements \JsonSerializable
{
    use \WhollyCrypto\PrestaShop\Sdk\Internal\RejectDynamicProperties;

    private const READABLE_PROPERTIES = ['method', 'url'];
    private string $method;
    private string $url;
    private array $headers;
    private ?string $body;

    /** @param array<string, string> $headers */
    public function __construct(
        string $method,
        string $url,
        #[\SensitiveParameter]
        array $headers,
        #[\SensitiveParameter]
        ?string $body = null
    ) {
        $this->initializeImmutable();
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function __debugInfo(): array
    {
        return ['method' => $this->method, 'url' => $this->url, 'bodyBytes' => strlen($this->body ?? '')];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Requests containing credentials must not be serialized.');
    }
}
