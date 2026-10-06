<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk;

use WhollyCrypto\PrestaShop\Sdk\Http\Response;
use WhollyCrypto\PrestaShop\Sdk\Http\TransportInterface;
use WhollyCrypto\PrestaShop\Sdk\Internal\JsonClient;
use WhollyCrypto\PrestaShop\Sdk\Internal\Validation;

/** Optional public checkout reader. It never holds or sends a merchant API token. */
final class CheckoutClient
{
    private JsonClient $http;

    public function __construct(string $checkoutBaseUrl, ?Options $options = null, ?TransportInterface $transport = null)
    {
        $this->http = new JsonClient($checkoutBaseUrl, null, $options, $transport);
    }

    public function getInvoice(string $publicInvoiceId): array
    {
        return $this->http->request('GET', '/checkout-api/invoices/' . Validation::uuid($publicInvoiceId), [], null, null, false);
    }

    public function getPreview(string $projectId, ?string $storeId = null): array
    {
        return $this->http->request('GET', '/checkout-api/previews/' . Validation::uuid($projectId), $storeId === null ? [] : ['store_id' => Validation::uuid($storeId)], null, null, false);
    }

    /** Prefer the current links.checkout returned by the merchant API when available. */
    public function invoiceUrl(string $publicInvoiceId): string
    {
        return $this->http->url('/invoice/' . Validation::uuid($publicInvoiceId));
    }

    public function previewUrl(string $projectId, ?string $storeId = null, string $state = 'waiting'): string
    {
        if (!in_array($state, ['waiting', 'confirming', 'paid', 'underpaid', 'expired'], true)) {
            throw new \InvalidArgumentException('Invalid illustrative checkout preview state.');
        }
        return $this->http->url('/invoice/preview/' . Validation::uuid($projectId), ['store_id' => $storeId === null ? null : Validation::uuid($storeId), 'state' => $state]);
    }

    public function lastResponse(): ?Response
    {
        return $this->http->lastResponse();
    }
}
