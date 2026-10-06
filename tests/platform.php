<?php
/** Run only on a disposable, freshly installed PrestaShop fixture. */
$root = getenv('WHOLLY_TEST_PRESTASHOP_ROOT');
if (!$root || getenv('WHOLLY_TEST_DISPOSABLE') !== 'yes') { fwrite(STDERR, "Disposable PrestaShop fixture required.\n"); exit(2); }
$_SERVER['HTTP_HOST'] = 'shop.example.test'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/config/config.inc.php';
if (!str_ends_with((string) Configuration::get('PS_SHOP_DOMAIN'), '.example.test')) throw new RuntimeException('Not a fixture domain');
require_once $root . '/config/bootstrap.php';
$kernel = new AdminKernel('prod', false); $kernel->boot();
require_once $root . '/modules/whollycrypto/whollycrypto.php';
use WhollyCrypto\PrestaShop\Protocol;

function ensure($yes, $message) { if (!$yes) throw new RuntimeException($message); }
function control(array $values): void {
    $c = curl_init(getenv('WHOLLY_TEST_API_ORIGIN').'/control');
    curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($values),CURLOPT_RETURNTRANSFER=>true]);
    ensure(curl_exec($c) !== false, 'TLS fixture');
}
$ctx = Context::getContext(); $ctx->shop = new Shop(1); Shop::setContext(Shop::CONTEXT_SHOP,1);
$ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$ctx->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$ctx->employee = new Employee(1);
Configuration::updateValue('PS_MAIL_METHOD',3);
$module = new Whollycrypto();
if ($module->id && !Configuration::getGlobalValue('WHOLLY_STATE_PENDING')) { $module->uninstall(); $module = new Whollycrypto(); }
if (!$module->id) ensure($module->install(), 'module install');
$module = Module::getInstanceByName('whollycrypto');
foreach (['api_url'=>getenv('WHOLLY_TEST_API_ORIGIN'),'pay_url'=>'https://pay.example.test','project_id'=>'11111111-1111-4111-8111-111111111111','store_id'=>'22222222-2222-4222-8222-222222222222','api_key'=>str_repeat('a',40),'ipn_secret'=>str_repeat('b',40)] as $key=>$value) Configuration::updateValue('WHOLLY_'.strtoupper($key),$value);
$s=$module->settings(); $repo=$module->repository();
function makeOrder($module, $ctx): Order {
    $customer=new Customer(); $customer->firstname='Fixture'; $customer->lastname='Customer'; $customer->email='test'.bin2hex(random_bytes(5)).'@example.invalid'; $customer->passwd=password_hash('FixturePass123!',PASSWORD_BCRYPT); ensure($customer->add(),'customer');
    $address=new Address(); $address->id_customer=$customer->id; $address->alias='Fixture'; $address->firstname='Fixture'; $address->lastname='Customer'; $address->address1='1 Test Street'; $address->city='Test'; $address->postcode='10001'; $address->id_country=Country::getByIso('US'); $address->id_state=State::getIdByIso('NY'); ensure($address->add(),'address');
    $product=new Product(); $product->name=[$ctx->language->id=>'Fixture item']; $product->link_rewrite=[$ctx->language->id=>'fixture-item']; $product->price=25; $product->active=1; $product->is_virtual=1; $product->id_category_default=2; $product->id_tax_rules_group=0; ensure($product->add(),'product'); $product->addToCategories([2]); StockAvailable::setQuantity($product->id,0,100);
    $cart=new Cart(); $cart->id_shop=1; $cart->id_shop_group=1; $cart->id_customer=$customer->id; $cart->id_address_delivery=$address->id; $cart->id_address_invoice=$address->id; $cart->id_currency=$ctx->currency->id; $cart->id_lang=$ctx->language->id; $cart->secure_key=$customer->secure_key; ensure($cart->add(),'cart'); $ctx->customer=$customer; $ctx->cart=$cart;
    ensure($cart->updateQty(1,$product->id) > 0,'cart product');
    $module->validateOrder($cart->id,(int)Configuration::getGlobalValue('WHOLLY_STATE_PENDING'),(float)$cart->getOrderTotal(true,Cart::BOTH),'Wholly Crypto',null,[],$ctx->currency->id,false,$customer->secure_key);
    $order=new Order($module->currentOrder); ensure(Validate::isLoadedObject($order),'native order'); return $order;
}
function start($module,$ctx,$repo,$s): array {
    control(['status'=>'new','amount_status'=>'none','sequence'=>1,'requires_review'=>false,'timing_status'=>'on_time','http_status'=>200]);
    $order=makeOrder($module,$ctx);
    $a=Protocol::attempt($s,$order->id,'prestashop:1:'.$order->id,Protocol::fiat((float)$order->total_paid,$ctx->currency->precision),$ctx->currency->iso_code,['ipn_url'=>'https://shop.example.test/ipn','redirect_url'=>'https://shop.example.test/return','cancel_url'=>'https://shop.example.test/return']);
    $repo->save($a); $a=Protocol::fetch($s,$a); $repo->save($a); return [$order,$a];
}
function notify($module,$a,$sequence,$status): array {
    $event='55555555-5555-4555-8555-555555555555'; $p=['payload_version'=>2,'event_id'=>$event,'event_type'=>$status==='settled'?'invoice.settled':'payment.received','invoice_id'=>$a['invoice_id'],'project_id'=>$a['project_id'],'store_id'=>$a['store_id'],'order_id'=>$a['payload']['order_id'],'amount'=>$a['payload']['amount'],'currency'=>$a['payload']['currency'],'sequence'=>$sequence,'status'=>$status];
    $raw=json_encode($p);$now=time();
    return $module->synchronize($a['order_id'],$raw,['Wholly-Signature'=>'t='.$now.',v1='.hash_hmac('sha256',$now.'.'.$raw,str_repeat('b',40)),'Wholly-Event-Id'=>$event,'Wholly-Delivery-Id'=>'66666666-6666-4666-8666-666666666666']);
}
[$order,$a]=start($module,$ctx,$repo,$s);
ensure(count($order->getOrderPaymentCollection())===0,'pending has no payment');
control(['status'=>'processing','amount_status'=>'partial','sequence'=>2]); notify($module,$a,2,'processing');
ensure(!(new Order($order->id))->hasBeenPaid(),'partial not paid');
control(['status'=>'settled','amount_status'=>'paid','sequence'=>3]); notify($module,$a,3,'settled'); notify($module,$a,3,'settled'); notify($module,$a,2,'processing');
$paid=new Order($order->id);ensure((int)$paid->current_state===(int)Configuration::get('PS_OS_PAYMENT'),'settlement paid');ensure(count($paid->getOrderPaymentCollection())===1,'only one native payment');
control(['status'=>'invalid','amount_status'=>'paid','sequence'=>4]); $module->synchronize($order->id);
ensure(count((new Order($order->id))->getOrderPaymentCollection())===1,'reorg does not add or refund payment');
[$expired,$b]=start($module,$ctx,$repo,$s);control(['status'=>'expired','amount_status'=>'none','sequence'=>2]);$module->synchronize($expired->id);
ensure((int)(new Order($expired->id))->current_state===(int)Configuration::get('PS_OS_CANCELED'),'unpaid expiry cancelled');
control(['status'=>'settled','amount_status'=>'paid','sequence'=>3]);$module->synchronize($expired->id);
ensure(!(new Order($expired->id))->hasBeenPaid(),'cancelled order never revived');
[$changed,$c]=start($module,$ctx,$repo,$s);$changed->total_paid=30;$changed->update();control(['status'=>'settled','amount_status'=>'paid','sequence'=>3]);$module->synchronize($changed->id);
ensure(!(new Order($changed->id))->hasBeenPaid(),'changed amount review');
$html=$module->getContent();ensure(str_contains($html,'Test saved connection')&&!str_contains($html,str_repeat('a',40)),'settings render and mask keys');
$options=$module->hookPaymentOptions(['cart'=>$ctx->cart]);ensure(count($options)===1,'native payment option');
$waiting=new OrderState((int)Configuration::getGlobalValue('WHOLLY_STATE_PENDING'));
$waiting->paid=true;$waiting->update();
try { ensure(count($module->hookPaymentOptions(['cart'=>$ctx->cart]))===0,'unsafe status hides method'); }
finally { $waiting->paid=false;$waiting->update(); }
echo 'PrestaShop '._PS_VERSION_.": native module install, virtual order, TLS creation, partial/settled/duplicate/old/reorg/expired/changed-total flows, admin settings and checkout option passed.\n";
