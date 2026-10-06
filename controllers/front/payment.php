<?php
use WhollyCrypto\PrestaShop\Protocol;

class WhollycryptoPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        try {
            $cart = $this->context->cart;
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$this->module->active
                || !hash_equals(Tools::getToken(false), (string) Tools::getValue('wholly_token'))
                || !Validate::isLoadedObject($cart) || !$cart->id_customer
                || (int) $cart->id_customer !== (int) $this->context->customer->id
                || !$cart->id_address_delivery || !$cart->id_address_invoice || !$this->module->checkCurrency($cart)) {
                throw new RuntimeException('Invalid checkout session.');
            }
            $available = array_column(Module::getPaymentModules(), 'name');
            if (!in_array($this->module->name, $available, true)) {
                throw new RuntimeException('Payment method is not available.');
            }
            // One cart must produce exactly one order; split shipments need separate checkouts.
            $packages = $cart->getPackageList();
            $count = array_sum(array_map('count', $packages));
            if ($count !== 1) {
                throw new RuntimeException('Split-shipment carts are not supported.');
            }
            $repo = $this->module->repository();
            $this->module->assertOrderStates();
            $repo->assertTransactional();
            $url = $repo->locked(-(int) $cart->id, function () use ($cart, $repo) {
                $id = (int) Order::getIdByCartId((int) $cart->id);
                if (!$id) {
                    $this->module->validateOrder((int) $cart->id,
                        (int) Configuration::getGlobalValue('WHOLLY_STATE_PENDING'),
                        (float) $cart->getOrderTotal(true, Cart::BOTH), $this->module->displayName,
                        null, [], (int) $cart->id_currency, false, $this->context->customer->secure_key);
                    $id = (int) $this->module->currentOrder;
                }
                return $repo->locked($id, function () use ($repo, $id) {
                    $order = new Order($id);
                    if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name
                        || (int) $order->id_customer !== (int) $this->context->customer->id
                        || (int) $order->id_shop !== (int) $this->context->shop->id) {
                        throw new RuntimeException('Order is not owned by this checkout.');
                    }
                    $a = $repo->load($id);
                    $s = $this->module->settings((int) $order->id_shop);
                    $currency = new Currency((int) $order->id_currency);
                    if ($a && (Protocol::fiat((float) $order->total_paid, (int) $currency->precision) !== $a['payload']['amount'] || $currency->iso_code !== $a['payload']['currency'])) {
                        throw new RuntimeException('Order total changed. Reconcile the existing invoice before taking another payment.');
                    }
                    if (!$a) {
                        if ((int) $order->current_state !== (int) Configuration::getGlobalValue('WHOLLY_STATE_PENDING')) {
                            throw new RuntimeException('Order is not awaiting payment.');
                        }
                        $return = $this->context->link->getPageLink('order-confirmation', true, null,
                            ['id_cart' => $order->id_cart, 'id_module' => $this->module->id, 'id_order' => $id, 'key' => $order->secure_key]);
                        $options = ['description' => 'PrestaShop order ' . $order->reference,
                            'ipn_url' => $this->context->link->getModuleLink('whollycrypto', 'ipn', ['order' => $id], true),
                            'redirect_url' => $return, 'cancel_url' => $return,
                            'metadata' => ['integration' => 'prestashop', 'shop_order_id' => (string) $id]];
                        if (Configuration::get('WHOLLY_SHARE_EMAIL')) {
                            $options['email'] = $this->context->customer->email;
                        }
                        $a = Protocol::attempt($s, $id, 'prestashop:' . $order->id_shop . ':' . $id,
                            Protocol::fiat((float) $order->total_paid, (int) $currency->precision), $currency->iso_code, $options);
                        $repo->save($a);
                    }
                    $a = Protocol::fetch($s, $a);
                    $repo->transaction(fn () => $this->module->applyInvoice($repo, $order, $a));
                    return $a['checkout_url'];
                });
            });
            Tools::redirect($url);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(Protocol::error($e), 2, null, 'Cart', (int) $this->context->cart->id, true);
            $this->errors[] = Protocol::error($e);
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
        }
    }
}
