<?php
declare(strict_types=1);
namespace WhollyCrypto\PrestaShop\Sdk;

use WhollyCrypto\PrestaShop\Sdk\Internal\JsonClient;
use WhollyCrypto\PrestaShop\Sdk\Internal\Validation;
use WhollyCrypto\PrestaShop\Sdk\Http\TransportInterface;

/** Server-side Operator API. Requires Operator mode, opt-in and a separate scoped key. */
final class OperatorClient
{
    private JsonClient $http;
    public function __construct(string $baseUrl,
        #[\SensitiveParameter]
        string $apiToken, ?Options $options = null, ?TransportInterface $transport = null)
    {
        if (!preg_match('/\Awc_operator_[a-f0-9]{32}_[a-f0-9]{64}\z/D', $apiToken)) {
            throw new \InvalidArgumentException('Configure a separate Operator API key, not a merchant key.');
        }
        $this->http = new JsonClient($baseUrl, $apiToken, $options, $transport);
    }
    public static function newIdempotencyKey(): string { return Client::newIdempotencyKey(); }
    public function lastResponse(): ?\WhollyCrypto\PrestaShop\Sdk\Http\Response { return $this->http->lastResponse(); }
    public function __debugInfo(): array { return ['client' => 'OperatorClient']; }
    public function __serialize(): array { throw new \LogicException('API clients must not be serialized.'); }
    private function write(string $path, array $data, string $idempotencyKey): array
    {
        if (!preg_match('/\A[A-Za-z0-9_.-]{16,128}\z/D', $idempotencyKey)) {
            throw new \InvalidArgumentException('Persist a 16–128 character idempotency key before sending.');
        }
        foreach (['amount','starting_credit'] as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];
                if ($field === 'amount' && substr($path, -20) === '/credits/adjustments' && is_string($value) && substr($value, 0, 1) === '-') { $value = substr($value, 1); }
                Validation::decimal($value, $field);
            }
        }
        return $this->http->request('POST', $path, [], $data, $idempotencyKey);
    }
    public function capabilities(array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/capabilities', $filters);
    }
    public function health(array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/health', $filters);
    }
    public function listMerchants(array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants', $filters);
    }
    public function createMerchant(array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants', $data, $idempotencyKey);
    }
    public function getMerchant(string $merchantId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '', $filters);
    }
    public function updateMerchant(string $merchantId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '', $data, $idempotencyKey);
    }
    public function listUsers(string $merchantId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/users', $filters);
    }
    public function createUser(string $merchantId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/users', $data, $idempotencyKey);
    }
    public function getUser(string $merchantId, string $userId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/users/' . Validation::uuid($userId) . '', $filters);
    }
    public function updateUser(string $merchantId, string $userId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/users/' . Validation::uuid($userId) . '', $data, $idempotencyKey);
    }
    public function setUserPassword(string $merchantId, string $userId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/users/' . Validation::uuid($userId) . '/password', $data, $idempotencyKey);
    }
    public function revokeUserSessions(string $merchantId, string $userId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/users/' . Validation::uuid($userId) . '/revoke-sessions', $data, $idempotencyKey);
    }
    public function listInvitations(string $merchantId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/invitations', $filters);
    }
    public function createInvitation(string $merchantId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/invitations', $data, $idempotencyKey);
    }
    public function getInvitation(string $invitationId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/invitations/' . Validation::uuid($invitationId) . '', $filters);
    }
    public function resendInvitation(string $invitationId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/invitations/' . Validation::uuid($invitationId) . '/resend', $data, $idempotencyKey);
    }
    public function revokeInvitation(string $invitationId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/invitations/' . Validation::uuid($invitationId) . '/revoke', $data, $idempotencyKey);
    }
    public function getCredits(string $merchantId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/credits', $filters);
    }
    public function listCreditLedger(string $merchantId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/credits/ledger', $filters);
    }
    public function adjustCredits(string $merchantId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/credits/adjustments', $data, $idempotencyKey);
    }
    public function listTopups(string $merchantId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/topups', $filters);
    }
    public function createTopup(string $merchantId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/topups', $data, $idempotencyKey);
    }
    public function getTopup(string $merchantId, string $topupId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/topups/' . Validation::uuid($topupId) . '', $filters);
    }
    public function reports(array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/reports', $filters);
    }
    public function listAudit(array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/audit', $filters);
    }
    public function listEvents(array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/events', $filters);
    }
    public function listWebhooks(array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/webhooks', $filters);
    }
    public function createWebhook(array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/webhooks', $data, $idempotencyKey);
    }
    public function updateWebhook(string $webhookId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/webhooks/' . Validation::uuid($webhookId) . '', $data, $idempotencyKey);
    }
    public function rotateWebhookSecret(string $webhookId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/webhooks/' . Validation::uuid($webhookId) . '/rotate', $data, $idempotencyKey);
    }
    public function listWebhookDeliveries(string $webhookId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/webhooks/' . Validation::uuid($webhookId) . '/deliveries', $filters);
    }
    public function listProjects(string $merchantId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects', $filters);
    }
    public function createProject(string $merchantId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects', $data, $idempotencyKey);
    }
    public function getProject(string $merchantId, string $projectId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '', $filters);
    }
    public function updateProject(string $merchantId, string $projectId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '', $data, $idempotencyKey);
    }
    public function listStores(string $merchantId, string $projectId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores', $filters);
    }
    public function createStore(string $merchantId, string $projectId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores', $data, $idempotencyKey);
    }
    public function getStore(string $merchantId, string $projectId, string $storeId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores/' . Validation::uuid($storeId) . '', $filters);
    }
    public function updateStore(string $merchantId, string $projectId, string $storeId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores/' . Validation::uuid($storeId) . '', $data, $idempotencyKey);
    }
    public function getStoreAppearance(string $merchantId, string $projectId, string $storeId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores/' . Validation::uuid($storeId) . '/checkout-appearance', $filters);
    }
    public function updateStoreAppearance(string $merchantId, string $projectId, string $storeId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores/' . Validation::uuid($storeId) . '/checkout-appearance', $data, $idempotencyKey);
    }
    public function listStorePaymentAssets(string $merchantId, string $projectId, string $storeId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores/' . Validation::uuid($storeId) . '/payment-assets', $filters);
    }
    public function updateStorePaymentAssets(string $merchantId, string $projectId, string $storeId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores/' . Validation::uuid($storeId) . '/payment-assets', $data, $idempotencyKey);
    }
    public function listStoreWebhooks(string $merchantId, string $projectId, string $storeId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores/' . Validation::uuid($storeId) . '/webhooks', $filters);
    }
    public function createStoreWebhook(string $merchantId, string $projectId, string $storeId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores/' . Validation::uuid($storeId) . '/webhooks', $data, $idempotencyKey);
    }
    public function updateStoreWebhook(string $merchantId, string $projectId, string $storeId, string $webhookId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/stores/' . Validation::uuid($storeId) . '/webhooks/' . Validation::uuid($webhookId) . '', $data, $idempotencyKey);
    }
    public function listInvoices(string $merchantId, string $projectId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/invoices', $filters);
    }
    public function getInvoice(string $merchantId, string $projectId, string $invoiceId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/invoices/' . Validation::uuid($invoiceId) . '', $filters);
    }
    public function listWallets(string $merchantId, string $projectId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/wallets', $filters);
    }
    public function listWalletAddresses(string $merchantId, string $projectId, string $walletId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/projects/' . Validation::uuid($projectId) . '/wallets/' . Validation::uuid($walletId) . '/addresses', $filters);
    }
    public function listMerchantCredentials(string $merchantId, array $filters = []): array
    {
        return $this->http->request('GET', '/v1/operator/merchants/' . Validation::uuid($merchantId) . '/api-credentials', $filters);
    }
    public function createMerchantCredential(string $merchantId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/api-credentials', $data, $idempotencyKey);
    }
    public function updateMerchantCredential(string $merchantId, string $credentialId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/api-credentials/' . Validation::uuid($credentialId) . '', $data, $idempotencyKey);
    }
    public function rotateMerchantCredential(string $merchantId, string $credentialId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/api-credentials/' . Validation::uuid($credentialId) . '/rotate', $data, $idempotencyKey);
    }
    public function revokeMerchantCredential(string $merchantId, string $credentialId, array $data, string $idempotencyKey): array
    {
        return $this->write('/v1/operator/merchants/' . Validation::uuid($merchantId) . '/api-credentials/' . Validation::uuid($credentialId) . '/revoke', $data, $idempotencyKey);
    }
}
