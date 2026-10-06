<?php
class WhollycryptoIpnModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    public function postProcess()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('{"error":"method_not_allowed"}');
        }
        $raw = file_get_contents('php://input', false, null, 0, 262145);
        if ($raw === false || strlen($raw) > 262144) {
            http_response_code(413);
            exit('{"error":"body_too_large"}');
        }
        try {
            $this->module->synchronize((int) Tools::getValue('order'), $raw, [
                'Wholly-Signature' => $_SERVER['HTTP_WHOLLY_SIGNATURE'] ?? '',
                'Wholly-Event-Id' => $_SERVER['HTTP_WHOLLY_EVENT_ID'] ?? '',
                'Wholly-Delivery-Id' => $_SERVER['HTTP_WHOLLY_DELIVERY_ID'] ?? '',
            ]);
            exit('{"ok":true}');
        } catch (WhollyCrypto\PrestaShop\Sdk\Exception\InvalidSignatureException $e) {
            http_response_code(401);
            exit('{"error":"invalid_signature"}');
        } catch (Throwable $e) {
            // Non-2xx deliberately asks Wholly Crypto to retry; never log raw customer payloads.
            http_response_code(503);
            header('Retry-After: 30');
            exit('{"error":"retry_later"}');
        }
    }
}
