<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk\Internal;

use WhollyCrypto\PrestaShop\Sdk\Client;
use WhollyCrypto\PrestaShop\Sdk\Exception\ApiException;
use WhollyCrypto\PrestaShop\Sdk\Exception\InvalidResponseException;
use WhollyCrypto\PrestaShop\Sdk\Exception\TransportException;
use WhollyCrypto\PrestaShop\Sdk\Http\CurlTransport;
use WhollyCrypto\PrestaShop\Sdk\Http\Request;
use WhollyCrypto\PrestaShop\Sdk\Http\Response;
use WhollyCrypto\PrestaShop\Sdk\Http\TransportInterface;
use WhollyCrypto\PrestaShop\Sdk\Options;

/** @internal */
final class JsonClient
{
    private string $origin;
    private Options $options;
    private TransportInterface $transport;
    private ?Response $lastResponse = null;
    private ?string $token;

    public function __construct(
        string $origin,
        #[\SensitiveParameter]
        ?string $token,
        ?Options $options,
        ?TransportInterface $transport
    ) {
        $this->options = $options ?? new Options();
        $this->origin = Validation::origin($origin, $this->options);
        if ($token !== null && !preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $token)) {
            throw new \InvalidArgumentException('API token must be nonempty visible ASCII without spaces or newlines.');
        }
        $this->transport = $transport ?? new CurlTransport();
        $this->token = $token;
    }

    public function url(string $path, array $query = []): string
    {
        if (!preg_match('~\A/(?:[a-zA-Z0-9/_-]|\.(?:svg|png))*\z~D', $path)
            || Compat::startsWith($path, '//') || Compat::contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid relative API path.');
        }
        $encoded = Validation::query($query);
        return $this->origin . $path . ($encoded !== '' ? '?' . $encoded : '');
    }

    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    public function request(string $method, string $path, array $query = [], ?array $data = null, ?string $idempotencyKey = null, bool $authenticated = true): array
    {
        $this->lastResponse = null;
        if (!in_array($method, ['GET', 'POST', 'PUT'], true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method.');
        }
        $headers = ['Accept' => 'application/json', 'User-Agent' => 'WhollyCrypto-PHP/' . Client::VERSION, 'Expect' => ''];
        if ($authenticated) {
            if ($this->token === null) {
                throw new \LogicException('This client has no merchant API credential.');
            }
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        if ($idempotencyKey !== null) {
            if (!preg_match('/\A[\x21-\x7e]{1,128}\z/D', $idempotencyKey)) {
                throw new \InvalidArgumentException('Idempotency key must contain 1–128 visible ASCII characters without spaces.');
            }
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $body = null;
        if ($data !== null) {
            try {
                $body = json_encode(Validation::canonical(Validation::object($data)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } catch (\JsonException $error) {
                throw new \InvalidArgumentException('Request contains an invalid JSON value or invalid UTF-8.');
            }
            if (strlen($body) > 32_768) {
                throw new \InvalidArgumentException('Merchant JSON body exceeds the 32 KiB limit.');
            }
            $headers['Content-Type'] = 'application/json';
        }
        $request = new Request($method, $this->url($path, $query), $headers, $body);
        $safeToRetry = $method === 'GET' || ($method === 'POST' && $idempotencyKey !== null);
        for ($attempt = 0; ; $attempt++) {
            $this->lastResponse = null;
            try {
                $response = $this->transport->send($request, $this->options);
                $this->lastResponse = $response;
            } catch (TransportException $error) {
                if (!$error->retryable || !$safeToRetry || $attempt >= $this->options->maxRetries) {
                    throw $error;
                }
                $this->pause($attempt, null);
                continue;
            }
            if ($safeToRetry && $attempt < $this->options->maxRetries
                && in_array($response->statusCode, [429, 502, 503, 504], true)
                && ($response->header('retry-after') === null || $response->retryAfterSeconds() !== null)
                && ($response->retryAfterSeconds() ?? 0) <= $this->options->maxRetryDelaySeconds) {
                $this->pause($attempt, $response->retryAfterSeconds());
                continue;
            }
            return $this->decode($response);
        }
    }

    private function pause(int $attempt, ?int $retryAfter): void
    {
        $delay = $retryAfter ?? min($this->options->maxRetryDelaySeconds, 2 ** $attempt);
        usleep($delay * 1_000_000 + random_int(0, 100_000));
    }

    private function decode(Response $response): array
    {
        $contentType = strtolower(explode(';', $response->header('content-type') ?? '')[0]);
        $isJson = $contentType === 'application/json' || preg_match('~\Aapplication/[a-z0-9.+-]+\+json\z~D', $contentType);
        $data = null;
        if ($isJson && Compat::startsWith(ltrim($response->body()), '{')) {
            try {
                $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException $error) {
                // On errors keep the actual HTTP status, even if a proxy returned bad JSON.
            }
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
            $code = is_string($error['code'] ?? null) && preg_match('/\A[a-z][a-z0-9_]{0,100}\z/D', $error['code'])
                ? $error['code'] : ($response->statusCode >= 300 && $response->statusCode < 400 ? 'redirect_not_followed' : 'http_error');
            if ($this->token !== null && Compat::contains($code, $this->token)) {
                $code = 'http_error';
            }
            $message = is_string($error['message'] ?? null) ? substr($error['message'], 0, 4096) : null;
            throw new ApiException($response->statusCode, $code, $message, $response);
        }
        if (!is_array($data)) {
            throw new InvalidResponseException('Expected a JSON object from the API. Check the API hostname; HTML login/error pages are not API responses.');
        }
        return $data;
    }

    public function __debugInfo(): array
    {
        return ['origin' => $this->origin, 'authenticated' => $this->token !== null];
    }

    public function __serialize(): array
    {
        throw new \LogicException('API clients containing credentials must not be serialized.');
    }
}
