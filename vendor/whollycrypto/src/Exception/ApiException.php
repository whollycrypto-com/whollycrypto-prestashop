<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk\Exception;

use WhollyCrypto\PrestaShop\Sdk\Http\Response;

/**
 * @property-read int $statusCode
 * @property-read string $errorCode
 */
final class ApiException extends \RuntimeException implements \JsonSerializable
{
    use \WhollyCrypto\PrestaShop\Sdk\Internal\RejectDynamicProperties;

    private const READABLE_PROPERTIES = ['statusCode', 'errorCode'];
    private int $statusCode;
    private string $errorCode;
    private ?string $apiMessage;
    private Response $response;

    public function __construct(
        int $statusCode,
        string $errorCode,
        ?string $apiMessage,
        Response $response
    ) {
        $this->initializeImmutable();
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->apiMessage = $apiMessage;
        $this->response = $response;
        // Do not put remote bodies, credentials or customer data into log messages.
        parent::__construct(sprintf('Wholly Crypto API request failed (HTTP %d, %s).', $statusCode, $errorCode) . $this->safePaymentSummary());
    }

    public function getApiMessage(): ?string
    {
        return $this->apiMessage;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    /** Explicit private diagnostics; do not log the complete result by default. */
    public function getDetails(): array
    {
        $body = json_decode($this->response->body(), true, 512, JSON_BIGINT_AS_STRING);
        $details = is_array($body) && is_array($body['error'] ?? null) ? ($body['error']['details'] ?? null) : null;
        return is_array($details) ? $details : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getPaymentMethodIssues(): array
    {
        $issues = $this->getDetails()['payment_methods'] ?? null;
        return is_array($issues) ? array_values(array_filter(array_slice($issues, 0, 64), 'is_array')) : [];
    }

    private function safePaymentSummary(): string
    {
        if (!in_array($this->errorCode, ['invalid_payment_request', 'no_ready_payment_methods', 'payment_rates_unavailable', 'lightning_unavailable'], true)) {
            return '';
        }
        // Only fixed local labels and bounded integer counts reach automatic logs.
        // Never interpolate remote message, ticker, asset ID, URL or customer data.
        $chains = ['ethereum'=>'Ethereum','base'=>'Base','bnb-chain'=>'BNB Smart Chain','hyperliquid'=>'Hyperliquid','bitcoin'=>'Bitcoin','bitcoin-cash'=>'Bitcoin Cash','litecoin'=>'Litecoin','dogecoin'=>'Dogecoin','zcash'=>'Zcash','solana'=>'Solana','tron'=>'TRON','xrp-ledger'=>'XRP Ledger','monero'=>'Monero','cardano'=>'Cardano','stellar'=>'Stellar','avalanche'=>'Avalanche','polygon'=>'Polygon','arbitrum'=>'Arbitrum One','optimism'=>'Optimism','ton'=>'TON','aptos'=>'Aptos','sui'=>'Sui','cosmos'=>'Cosmos Hub','polkadot'=>'Polkadot Hub','near'=>'NEAR','algorand'=>'Algorand','hedera'=>'Hedera','kaspa'=>'Kaspa','tezos'=>'Tezos','dash'=>'Dash'];
        $reasons = [
            'scanner_provider_quorum'=>'Two independent scanner-compatible providers are required. Check Settings > Chain connections.',
            'scanner_not_checked'=>'Scanner checks are missing or stale. Check Settings > Chain connections.',
            'scanner_unavailable'=>'Receive scanner unavailable. Check Settings > Chain connections.',
            'wallet_missing'=>'Create the project wallet.', 'wallet_disabled'=>'Enable the project wallet.',
            'wallet_backup_required'=>'Back up the project wallet and confirm its backup.',
            'wallet_key_unavailable'=>'Wallet key unavailable. Check the project wallet configuration.',
            'wallet_activation_required'=>'Verify on-chain activation of the receiving account.',
            'monero_binding_unavailable'=>'Verify the view-only Monero wallet connection and backup.',
            'custom_rate_unavailable'=>'Configure a current custom token price.',
            'rate_unavailable'=>'A fresh trustworthy exchange rate is unavailable. Check Settings > Rates.',
            'lightning_unavailable'=>'Check the Lightning connection and project access.',
            'project_disabled'=>'Enable the project.', 'store_disabled'=>'Enable the store.',
            'chain_disabled'=>'Enable the chain in payment methods.', 'asset_disabled'=>'Enable the asset in payment methods.',
            'asset_not_accepted'=>'Select an asset accepted by the store.',
        ];
        $messages = [];
        foreach ($this->getPaymentMethodIssues() as $issue) {
            $reason = $issue['reason_code'] ?? null;
            if (!is_string($reason) || !isset($reasons[$reason])) { continue; }
            $chain = $issue['chain_slug'] ?? null;
            $label = is_string($chain) ? ($chains[$chain] ?? 'Payment method') : 'Payment method';
            $count = $issue['usable_independent_providers'] ?? null;
            $prefix = $reason === 'scanner_provider_quorum' && is_int($count) && $count >= 0 && $count <= 2 ? "$count of 2 independent scanner providers are usable. " : '';
            $messages[] = "$label: $prefix" . $reasons[$reason];
        }
        $messages = array_values(array_unique($messages));
        return $messages ? ' ' . implode(' ', array_slice($messages, 0, 3)) . (count($messages) > 3 ? ' More payment-method issues are available in getPaymentMethodIssues().' : '') : '';
    }

    public function getRetryAfter(): ?int
    {
        return $this->response->retryAfterSeconds();
    }

    public function __debugInfo(): array
    {
        return ['statusCode' => $this->statusCode, 'errorCode' => $this->errorCode];
    }
}
