<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/autoload.php';
use WhollyCrypto\PrestaShop\Protocol;

$s = Protocol::settings(['api_url'=>getenv('WHOLLY_TEST_API_ORIGIN'), 'pay_url'=>'https://pay.example.test',
    'project_id'=>'11111111-1111-4111-8111-111111111111','store_id'=>'22222222-2222-4222-8222-222222222222',
    'api_key'=>str_repeat('a',40),'ipn_secret'=>str_repeat('b',40)]);
$a = Protocol::attempt($s,42,'fixture:42','25.00','EUR',['ipn_url'=>'https://shop.example.test/ipn','redirect_url'=>'https://shop.example.test/return','cancel_url'=>'https://shop.example.test/return']);
$first = Protocol::fetch($s,$a);
$retry = Protocol::fetch($s,$a);
if ($first['invoice_id'] !== $retry['invoice_id']) throw new RuntimeException('Duplicate invoice');
$read = Protocol::fetch($s,$first);
if ($read !== $first) throw new RuntimeException('Read mismatch');
$c = curl_init($s['api_url'].'/control');
curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>' {"status":"settled","amount_status":"paid","sequence":3}',CURLOPT_RETURNTRANSFER=>true]);
if (curl_exec($c) === false) throw new RuntimeException('Control failed');
$settled = Protocol::fetch($s,$first);
if (Protocol::decision($settled) !== 'paid') throw new RuntimeException('Not settled');
echo "Real HTTPS: verified TLS, create, saved-key retry, GET and settlement passed.\n";
