<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk;

use WhollyCrypto\PrestaShop\Sdk\Exception\InvalidResponseException;
use WhollyCrypto\PrestaShop\Sdk\Http\Response;
use WhollyCrypto\PrestaShop\Sdk\Http\TransportInterface;
use WhollyCrypto\PrestaShop\Sdk\Internal\JsonClient;
use WhollyCrypto\PrestaShop\Sdk\Internal\Compat;
use WhollyCrypto\PrestaShop\Sdk\Internal\Validation;

/** Merchant API client. Methods preserve the API's complete response envelope. */
final class Client
{
    public const VERSION = '2.6.0';
    private JsonClient $http;

    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter]
        string $apiToken,
        ?Options $options = null,
        ?TransportInterface $transport = null
    ) {
        $this->http = new JsonClient($baseUrl, $apiToken, $options, $transport);
    }

    /** Generate once, persist with the original invoice request, then reuse on retries. */
    public static function newIdempotencyKey(): string
    {
        return bin2hex(random_bytes(24));
    }

    public function serviceInfo(): array
    {
        return $this->http->request('GET', '/', [], null, null, false);
    }

    public function health(): array
    {
        return $this->http->request('GET', '/healthz', [], null, null, false);
    }

    public function createInvoice(string $projectId, string $storeId, array $invoice, string $idempotencyKey): array
    {
        Validation::decimal($invoice['amount'] ?? null, 'amount');
        foreach (['exchange_rate_spread_percent', 'underpayment_tolerance_percent'] as $field) {
            if (isset($invoice[$field])) {
                Validation::decimal($invoice[$field], $field);
            }
        }
        foreach (['metadata', 'checkout_appearance'] as $field) {
            if (isset($invoice[$field]) && is_array($invoice[$field])) {
                $invoice[$field] = Validation::object($invoice[$field]);
            }
        }
        return $this->http->request('POST', $this->storePath($projectId, $storeId) . '/invoices', [], $invoice, $idempotencyKey);
    }

    /** Filters: store_id, status, search, limit (1–100), offset (0–1,000,000). */
    public function listInvoices(string $projectId, array $filters = []): array
    {
        return $this->http->request('GET', $this->projectPath($projectId) . '/invoices', $filters);
    }

    public function getInvoice(string $projectId, string $publicInvoiceId): array
    {
        return $this->http->request('GET', $this->projectPath($projectId) . '/invoices/' . Validation::uuid($publicInvoiceId));
    }

    /** Current observations; filters: payment_method_id, limit (1–100), offset (0–1,000,000). */
    public function listInvoicePayments(string $projectId, string $invoiceId, array $filters = []): array
    {
        return $this->http->request('GET', $this->projectPath($projectId) . '/invoices/' . Validation::uuid($invoiceId) . '/payments', $filters);
    }

    /** Lazy pagination. Pages are separate snapshots; use a durable ID to deduplicate. */
    public function iterateInvoices(string $projectId, array $filters = []): \Generator
    {
        $offset = $filters['offset'] ?? 0;
        $limit = $filters['limit'] ?? 50;
        if (!is_int($offset) || $offset < 0 || $offset > 1_000_000 || !is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid invoice pagination limit or offset.');
        }
        while (true) {
            $page = $this->listInvoices($projectId, array_replace($filters, ['limit' => $limit, 'offset' => $offset]));
            $pagination = $page['pagination'] ?? null;
            if (!is_array($page['data'] ?? null) || !Compat::isList($page['data'])
                || !is_array($pagination) || ($pagination['offset'] ?? null) !== $offset
                || ($pagination['limit'] ?? null) !== $limit || !is_bool($pagination['has_more'] ?? null)) {
                throw new InvalidResponseException('Invoice response has invalid pagination metadata.');
            }
            foreach ($page['data'] as $invoice) {
                if (!is_array($invoice)) {
                    throw new InvalidResponseException('Invoice list contains an invalid item.');
                }
                yield $invoice;
            }
            if (!$pagination['has_more']) {
                return;
            }
            if ($page['data'] === [] || $offset + $limit > 1_000_000) {
                throw new InvalidResponseException('Invoice pagination cannot advance safely.');
            }
            $offset += $limit;
        }
    }

    public function listProjectPaymentAssets(string $projectId): array
    {
        return $this->http->request('GET', $this->projectPath($projectId) . '/payment-assets');
    }

    public function updateProjectPaymentAsset(string $projectId, string $assetId, array $policy): array
    {
        return $this->http->request('PUT', $this->projectPath($projectId) . '/payment-assets/' . Validation::uuid($assetId), [], $policy);
    }

    public function listTokenCandidates(string $projectId, string $chainSlug, array $filters = []): array
    {
        return $this->http->request('GET', $this->projectPath($projectId) . '/payment-token-candidates', array_replace($filters, ['chain_slug' => $chainSlug]));
    }

    public function registerTokenAsset(string $projectId, array $token): array
    {
        return $this->http->request('POST', $this->projectPath($projectId) . '/payment-token-assets', [], $token);
    }

    public function discoverCustomDexPools(string $projectId, string $chainSlug, string $contractAddress): array
    {
        return $this->http->request('GET', $this->projectPath($projectId) . '/payment-token-dex-pools', ['chain_slug' => $chainSlug, 'contract_address' => $contractAddress]);
    }

    public function registerCustomToken(string $projectId, array $token): array
    {
        if (isset($token['price_usd'])) {
            Validation::decimal($token['price_usd'], 'price_usd');
        }
        return $this->http->request('POST', $this->projectPath($projectId) . '/payment-token-assets/custom', [], $token);
    }

    /** On-chain selections are in data; separate Lightning readiness is in lightning. */
    public function listStorePaymentAssets(string $projectId, string $storeId): array
    {
        return $this->http->request('GET', $this->storePath($projectId, $storeId) . '/payment-assets');
    }

    /** Replaces the entire accepted on-chain asset list. An empty list removes all. */
    public function updateStorePaymentAssets(string $projectId, string $storeId, array $assets): array
    {
        if (!Compat::isList($assets)) {
            throw new \InvalidArgumentException('assets must be a list of asset_id/display_order objects.');
        }
        return $this->http->request('PUT', $this->storePath($projectId, $storeId) . '/payment-assets', [], ['assets' => $assets]);
    }

    public function updateStoreConfirmationPolicy(string $projectId, string $storeId, string $assetId, array $policy): array
    {
        return $this->http->request('PUT', $this->storePath($projectId, $storeId) . '/payment-assets/' . Validation::uuid($assetId) . '/confirmation-policy', [], $policy);
    }

    /** Public wallet details and balances only. Never returns keys or recovery phrases. */
    public function listProjectWallets(string $projectId): array
    {
        return $this->http->request('GET', $this->projectPath($projectId) . '/wallets');
    }

    /** Filters: status, reason, search, store_id, page. Fixed 25 cases per page. */
    public function listReconciliation(string $projectId, array $filters = []): array
    {
        return $this->http->request('GET', $this->projectPath($projectId) . '/reconciliation', $filters);
    }

    /** page selects decision history, not the observations/deliveries arrays. */
    public function getReconciliation(string $projectId, string $publicInvoiceId, int $page = 1): array
    {
        return $this->http->request('GET', $this->projectPath($projectId) . '/reconciliation/' . Validation::uuid($publicInvoiceId), ['page' => $page]);
    }

    public function lastResponse(): ?Response
    {
        return $this->http->lastResponse();
    }

    private function projectPath(string $projectId): string
    {
        return '/v1/projects/' . Validation::uuid($projectId);
    }

    private function storePath(string $projectId, string $storeId): string
    {
        return $this->projectPath($projectId) . '/stores/' . Validation::uuid($storeId);
    }

    public function __debugInfo(): array
    {
        return $this->http->__debugInfo();
    }
}
