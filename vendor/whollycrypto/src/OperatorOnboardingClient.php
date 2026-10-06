<?php
declare(strict_types=1);
namespace WhollyCrypto\PrestaShop\Sdk;
use WhollyCrypto\PrestaShop\Sdk\Internal\JsonClient;
use WhollyCrypto\PrestaShop\Sdk\Http\TransportInterface;

/** Invitation-token-only API. No Operator or merchant key is accepted or sent. */
final class OperatorOnboardingClient
{
    private JsonClient $http;
    public function __construct(string $apiBaseUrl, ?Options $options = null, ?TransportInterface $transport = null)
    { $this->http = new JsonClient($apiBaseUrl, null, $options, $transport); }
    public function checkInvitation(string $token): array
    { return $this->http->request('POST', '/v1/onboarding/invitations/check', [], ['token'=>$token], null, false); }
    /** No automatic retry or login. On ambiguous response, check the link and try signing in. */
    public function acceptInvitation(string $token,
        #[\SensitiveParameter]
        string $password, bool $custodyAcknowledged): array
    { return $this->http->request('POST', '/v1/onboarding/invitations/accept', [], ['token'=>$token,'password'=>$password,'custody_acknowledged'=>$custodyAcknowledged], null, false); }
}
