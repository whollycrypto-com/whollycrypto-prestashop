<?php
/** MIT licensed Wholly Crypto payment module. */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/lib/autoload.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use WhollyCrypto\PrestaShop\Protocol;
use WhollyCrypto\PrestaShop\Repository;

class Whollycrypto extends PaymentModule
{
    private const FIELDS = [
        'api_url' => 'API origin (https://api.example.com)',
        'pay_url' => 'Checkout origin (https://pay.example.com)',
        'project_id' => 'Project API UUID', 'store_id' => 'Store API UUID',
        'api_key' => 'API credential', 'ipn_secret' => 'Store IPN signing secret',
    ];

    public function __construct()
    {
        $this->name = 'whollycrypto';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Wholly Crypto';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        parent::__construct();
        $this->displayName = $this->l('Wholly Crypto');
        $this->description = $this->l('Crypto payments through your own Wholly Crypto hosted checkout.');
        $this->confirmUninstall = $this->l('Stop accepting new payments? Connection settings and payment records are retained. Keep the module installed until all open payments are reconciled.');
    }

    public function install()
    {
        if (PHP_VERSION_ID < 80100 || !extension_loaded('curl')) {
            $this->_errors[] = $this->l('PHP 8.1 or newer and cURL are required.');
            return false;
        }
        if (!parent::install()) {
            return false;
        }
        try {
            $this->repository()->install();
            foreach (['pending' => ['Awaiting Wholly Crypto payment', '#5167bd'], 'review' => ['Wholly Crypto: review payment', '#bd772b']] as $key => $info) {
                if (!Validate::isLoadedObject(new OrderState((int) Configuration::getGlobalValue('WHOLLY_STATE_' . strtoupper($key))))) {
                    $state = new OrderState();
                    foreach (Language::getLanguages(false) as $language) {
                        $state->name[(int) $language['id_lang']] = $info[0];
                    }
                    $state->color = $info[1];
                    $state->module_name = $this->name;
                    $state->send_email = false;
                    $state->logable = false;
                    $state->paid = false;
                    $state->invoice = false;
                    $state->delivery = false;
                    $state->unremovable = true;
                    if (!$state->add() || !Configuration::updateGlobalValue('WHOLLY_STATE_' . strtoupper($key), (int) $state->id)) {
                        throw new RuntimeException('Could not create order state.');
                    }
                }
            }
            foreach (['paymentOptions', 'paymentReturn', 'displayAdminOrderMainBottom'] as $hook) {
                if (!$this->registerHook($hook)) {
                    throw new RuntimeException('Could not register payment hook.');
                }
            }
            return true;
        } catch (Throwable $e) {
            $this->_errors[] = Protocol::error($e);
            return false;
        }
    }

    public function repository(): Repository
    {
        $db = Db::getInstance();
        return new Repository(
            static fn (string $sql) => $db->execute($sql),
            static fn (string $sql) => $db->executeS($sql, true, false),
            static fn (string $value) => pSQL($value, true), _DB_PREFIX_ . 'whollycrypto_payment',
            array_map(static fn (string $name) => _DB_PREFIX_ . $name,
                ['orders', 'order_payment', 'order_invoice_payment', 'order_history', 'order_invoice', 'order_detail', 'stock_available', 'message'])
        );
    }

    public function settings(?int $shopId = null): array
    {
        $shopId = $shopId ?? (int) $this->context->shop->id;
        $shop = new Shop($shopId);
        $s = [];
        foreach (self::FIELDS as $key => $label) {
            $s[$key] = Configuration::get('WHOLLY_' . strtoupper($key), null, (int) $shop->id_shop_group, $shopId);
        }
        return Protocol::settings($s);
    }

    public function assertOrderStates(): void
    {
        $ids = [];
        foreach (['PENDING', 'REVIEW'] as $name) {
            $state = new OrderState((int) Configuration::getGlobalValue('WHOLLY_STATE_' . $name));
            if (!Validate::isLoadedObject($state) || $state->paid || $state->logable || $state->invoice || $state->delivery) {
                throw new RuntimeException('Wholly Crypto waiting and review states must remain unpaid and must not trigger fulfillment.');
            }
            $ids[] = (int) $state->id;
        }
        $paid = new OrderState((int) Configuration::get('PS_OS_PAYMENT'));
        $expired = new OrderState((int) Configuration::get('PS_OS_CANCELED'));
        if (!Validate::isLoadedObject($paid) || !$paid->paid || !Validate::isLoadedObject($expired) || $expired->paid
            || count(array_unique(array_merge($ids, [(int) $paid->id, (int) $expired->id]))) !== 4) {
            throw new RuntimeException('Review the shop payment and cancellation states before accepting crypto.');
        }
    }

    private static function h($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function getContent()
    {
        if (!$this->getPermission('configure')) {
            return $this->displayError($this->l('You cannot configure this module.'));
        }
        if (Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return $this->displayError($this->l('Select one shop before configuring its Wholly Crypto connection.'));
        }
        $output = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Tools::isSubmit('wholly_action')) {
            try {
                if (!hash_equals(Tools::getAdminTokenLite('AdminModules'), (string) Tools::getValue('wholly_token'))) {
                    throw new RuntimeException('Invalid admin token.');
                }
                $action = (string) Tools::getValue('wholly_action');
                if ($action === 'save') {
                    $s = [];
                    foreach (self::FIELDS as $key => $label) {
                        $value = trim((string) Tools::getValue('wholly_' . $key, ''));
                        if (in_array($key, ['api_key', 'ipn_secret'], true) && $value === '') {
                            $value = (string) Configuration::get('WHOLLY_' . strtoupper($key));
                        }
                        $s[$key] = $value;
                    }
                    $s = Protocol::settings($s);
                    foreach ($s as $key => $value) {
                        if (!Configuration::updateValue('WHOLLY_' . strtoupper($key), $value)) {
                            throw new RuntimeException('Configuration storage failed.');
                        }
                    }
                    Configuration::updateValue('WHOLLY_SHARE_EMAIL', Tools::getValue('wholly_share_email') === '1' ? 1 : 0);
                    $output .= $this->displayConfirmation($this->l('Connection saved. Test it before accepting payments.'));
                } elseif ($action === 'test') {
                    $s = $this->settings();
                    Protocol::client($s)->listStorePaymentAssets($s['project_id'], $s['store_id']);
                    $output .= $this->displayConfirmation($this->l('API connection and store access work. This does not test payment creation or callback delivery; complete the staging checklist.'));
                } elseif ($action === 'reconcile') {
                    $id = (int) Tools::getValue('wholly_order_id');
                    $order = new Order($id);
                    if (!Validate::isLoadedObject($order) || (int) $order->id_shop !== (int) $this->context->shop->id) {
                        throw new RuntimeException('Order outside this shop.');
                    }
                    $this->synchronize($id);
                    $output .= $this->displayConfirmation($this->l('Invoice checked. Review the order status and Wholly Crypto console.'));
                }
            } catch (Throwable $e) {
                $output .= $this->displayError(Protocol::error($e));
            }
        }
        $action = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');
        $form = '<form method="post" action="' . self::h($action) . '"><input type="hidden" name="wholly_token" value="' . self::h(Tools::getAdminTokenLite('AdminModules')) . '">';
        $output .= '<div class="panel"><h3>Wholly Crypto</h3><p>Connect your own installation. Copy the API UUIDs from Project → Stores → Basic → API IDs. Use a project-restricted read/write API credential and the same store IPN signing secret.</p>' . $form;
        foreach (self::FIELDS as $key => $label) {
            $secret = in_array($key, ['api_key', 'ipn_secret'], true);
            $value = $secret ? '' : (string) Configuration::get('WHOLLY_' . strtoupper($key));
            $output .= '<div class="form-group"><label for="wholly_' . $key . '">' . self::h($label) . '</label><input class="form-control" id="wholly_' . $key . '" name="wholly_' . $key . '" type="' . ($secret ? 'password' : 'text') . '" autocomplete="off" value="' . self::h($value) . '">' . ($secret ? '<small>Leave blank to keep the saved value. Never enter wallet keys.</small>' : '') . '</div>';
        }
        $output .= '<div class="checkbox"><label><input type="checkbox" name="wholly_share_email" value="1"' . (Configuration::get('WHOLLY_SHARE_EMAIL') ? ' checked' : '') . '> Include customer email in the Wholly Crypto invoice (optional)</label></div><button class="btn btn-primary" name="wholly_action" value="save">Save connection</button> <button class="btn btn-default" name="wholly_action" value="test">Test saved connection</button></form></div>';
        $output .= '<div class="panel"><h3>Recover an order</h3><p>Check an existing invoice after a delayed callback. This never creates a second payment request.</p>' . $form . '<div class="form-group"><label for="wholly_order_id">PrestaShop order ID</label><input class="form-control" type="number" min="1" name="wholly_order_id" id="wholly_order_id" required></div><button class="btn btn-default" name="wholly_action" value="reconcile">Check payment</button></form><p style="margin-top:16px"><a href="https://github.com/whollycrypto-com/whollycrypto-prestashop#readme" target="_blank" rel="noopener noreferrer">Setup, callback and staging guide</a></p></div>';
        return $output;
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->checkCurrency($params['cart'])) {
            return [];
        }
        try {
            $this->settings();
            $this->assertOrderStates();
        } catch (Throwable $e) {
            return [];
        }
        $option = new PaymentOption();
        $option->setCallToActionText($this->l('Pay with crypto'))
            ->setModuleName($this->name)
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true))
            ->setInputs(['wholly_token' => ['name' => 'wholly_token', 'type' => 'hidden', 'value' => Tools::getToken(false)]])
            ->setLogo($this->_path . 'views/img/logo.svg')
            ->setAdditionalInformation('<p>' . self::h($this->l('Choose a coin or token on our secure Wholly Crypto checkout. Your order is confirmed after payment verification.')) . '</p>');
        return [$option];
    }

    public function checkCurrency($cart)
    {
        $currencies = $this->getCurrency((int) $cart->id_currency);
        return is_array($currencies) && in_array((int) $cart->id_currency, array_map('intval', array_column($currencies, 'id_currency')), true);
    }

    /** Called only after the signed callback, or a permission-checked admin recovery request. */
    public function synchronize(int $orderId, ?string $raw = null, array $headers = []): array
    {
        $this->assertOrderStates();
        $repo = $this->repository();
        return $repo->locked($orderId, function () use ($repo, $orderId, $raw, $headers) {
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order) || $order->module !== $this->name || (int) $order->id_shop !== (int) $this->context->shop->id) {
                throw new RuntimeException('Unknown payment order.');
            }
            $s = $this->settings((int) $order->id_shop);
            $a = $repo->load($orderId);
            if (!$a) {
                throw new RuntimeException('No payment attempt exists.');
            }
            $event = $raw !== null ? Protocol::notification($a, $raw, $headers, $s['ipn_secret']) : null;
            $updated = Protocol::fetch($s, $a);
            if ($event && $updated['sequence'] < $event['sequence']) {
                throw new RuntimeException('Waiting for current API state.');
            }
            return $repo->transaction(function () use ($repo, $order, $updated) {
                return $this->applyInvoice($repo, $order, $updated);
            });
        });
    }

    public function applyInvoice(Repository $repo, Order $order, array $a): array
    {
        $currency = new Currency((int) $order->id_currency);
        if (Protocol::fiat((float) $order->total_paid, (int) $currency->precision) !== $a['payload']['amount'] || $currency->iso_code !== $a['payload']['currency']) {
            $a['review'] = true;
        }
        $decision = Protocol::decision($a);
        $pending = (int) Configuration::getGlobalValue('WHOLLY_STATE_PENDING');
        $review = (int) Configuration::getGlobalValue('WHOLLY_STATE_REVIEW');
        $paid = (int) Configuration::get('PS_OS_PAYMENT', null, null, (int) $order->id_shop);
        $expired = (int) Configuration::get('PS_OS_CANCELED', null, null, (int) $order->id_shop);
        if ($a['applied'] === 'paid') {
            // A later reorg is an operator action, never a second credit or automatic refund.
            if ($decision !== 'paid' && ($a['applied_note'] ?? '') !== 'review') {
                $this->privateNote((int) $order->id, 'Wholly Crypto: payment changed after settlement. Review the invoice; no automatic refund or order reversal was made.');
                $a['applied_note'] = 'review';
            }
        } elseif ($decision !== 'pending' && $a['applied'] !== $decision) {
            if ($decision === 'paid' && (int) $order->current_state !== $pending) {
                $decision = 'review'; // Do not revive cancelled, shipped or manually changed orders.
            }
            $target = ['paid' => $paid, 'review' => $review, 'expired' => $expired][$decision];
            if ($decision === 'paid') {
                if (count($order->getOrderPaymentCollection()) !== 0) {
                    $decision = 'review';
                    $target = $review;
                } elseif (!$order->addOrderPayment((float) $a['payload']['amount'], $this->displayName, $a['invoice_id'], $currency)) {
                    throw new RuntimeException('Could not record verified order payment.');
                }
            }
            if ((int) $order->current_state === $pending || $decision === 'paid') {
                $history = new OrderHistory();
                $history->id_order = (int) $order->id;
                $history->changeIdOrderState($target, $order, true);
                if (!$history->add()) {
                    throw new RuntimeException('Could not save order state.');
                }
            }
            $this->privateNote((int) $order->id, 'Wholly Crypto invoice ' . $a['invoice_id'] . ': ' . $decision . '.');
            $a['applied'] = $decision;
        }
        $repo->save($a);
        return $a;
    }

    private function privateNote(int $order, string $text): void
    {
        $message = new Message();
        $message->id_order = $order;
        $message->message = $text;
        $message->private = true;
        if (!$message->add()) {
            throw new RuntimeException('Could not save payment note.');
        }
    }

    public function hookPaymentReturn($params)
    {
        $order = $params['order'] ?? null;
        if (!$order || $order->module !== $this->name) {
            return '';
        }
        $html = '<p>' . self::h($this->l('Wholly Crypto verifies your payment in the background. This return page is not proof of payment. You can check your order status in your account.')) . '</p>';
        $a = $this->repository()->load((int) $order->id);
        if ($a && $a['checkout_url']) {
            $html .= '<p><a class="btn btn-primary" href="' . self::h($a['checkout_url']) . '">' . self::h($this->l('View crypto payment')) . '</a></p>';
        }
        return $html;
    }

    public function hookDisplayAdminOrderMainBottom($params)
    {
        $a = $this->repository()->load((int) ($params['id_order'] ?? 0));
        if (!$a) {
            return '';
        }
        return '<div class="card"><div class="card-body"><h3>Wholly Crypto</h3><p>Invoice: <code>' . self::h($a['invoice_id'] ?: 'Creation pending: retry the same order') . '</code></p><p>Status: ' . self::h($a['status']) . ' · ' . self::h($a['amount_status']) . '</p><p>Use Modules → Wholly Crypto → Recover an order to check its current payment state.</p></div></div>';
    }
}
