<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/autoload.php';
use WhollyCrypto\PrestaShop\Protocol;
use WhollyCrypto\PrestaShop\Sdk\Http\Request;
use WhollyCrypto\PrestaShop\Sdk\Http\Response;
use WhollyCrypto\PrestaShop\Sdk\Http\TransportInterface;
use WhollyCrypto\PrestaShop\Sdk\Options;

set_error_handler(static function ($level, $message, $file, $line) { throw new ErrorException($message, 0, $level, $file, $line); });
$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); ++$checks; }
function rejects(callable $call, string $label): void { try { $call(); } catch (Throwable) { check(true, $label); return; } throw new RuntimeException('Accepted: ' . $label); }

final class FixtureTransport implements TransportInterface
{
    public array $requests = [];
    public array $invoice;
    public string $url;
    public int $status = 200;
    public function send(Request $r, Options $o): Response {
        $this->requests[] = $r;
        return new Response($this->status, ['content-type' => 'application/json'], json_encode($this->status === 200
            ? ['data' => $this->invoice, 'links' => ['checkout' => $this->url]]
            : ['error' => ['code' => 'unavailable', 'message' => 'DO NOT LOG PRIVATE RESPONSE']], JSON_THROW_ON_ERROR));
    }
}
$s = Protocol::settings(['api_url' => 'https://api.example.test/', 'pay_url' => 'https://pay.example.test/',
    'project_id' => '11111111-1111-4111-8111-111111111111', 'store_id' => '22222222-2222-4222-8222-222222222222',
    'api_key' => str_repeat('a', 40), 'ipn_secret' => str_repeat('b', 40)]);
$a = Protocol::attempt($s, 42, 'shop:42', '25.00', 'EUR', ['ipn_url' => 'https://shop.example.test/ipn', 'redirect_url' => 'https://shop.example.test/return', 'cancel_url' => 'https://shop.example.test/return']);
$invoice = ['invoice_id' => '33333333-3333-4333-8333-333333333333', 'project_id' => $s['project_id'], 'store_id' => $s['store_id'],
    'order_id' => 'shop:42', 'amount' => '25.0000', 'currency' => 'EUR', 'status' => 'new', 'amount_status' => 'none',
    'sequence' => 1, 'timing_status' => 'on_time'];
$t = new FixtureTransport(); $t->invoice = $invoice; $t->url = $s['pay_url'] . '/invoice/' . $invoice['invoice_id'];
check(Protocol::decimal('000.0001') === '0.0001', 'decimal normalization');
check(Protocol::decimal('999999999999999999.000000001') === '999999999999999999.000000001', 'exact decimal');
check(Protocol::fiat(25.1234, 2) === '25.12', 'platform currency precision');
check(Protocol::fiat(250, 0) === '250', 'zero decimal fiat');
foreach (['-1', '1e3', '', '.1', '1.', '1,2', 25.0, [], null] as $bad) rejects(fn () => Protocol::decimal($bad), 'invalid amount');
foreach ([[-1, 2], [INF, 2], [10, -1], [10, 9], [0, 2]] as $args) rejects(fn () => Protocol::fiat(...$args), 'invalid fiat');
foreach (['http://api.example.test', 'https://user:pass@example.test', 'https://api.example.test/v1', 'https://api.example.test/?a=1', "https://api.example.test\n", 'https://api.example.test/#x'] as $url) rejects(fn () => Protocol::https($url, true), 'invalid origin');
rejects(fn () => Protocol::settings(array_replace($s, ['project_id' => 'my-project'])), 'not UUID');
rejects(fn () => Protocol::settings(array_replace($s, ['api_key' => ''])), 'empty credential');
rejects(fn () => Protocol::attempt($s, 1, 'x', '0', 'EUR', $a['payload']), 'zero order');
$saved = Protocol::fetch($s, $a, $t);
check($saved['review'] === false, 'invoice detail does not require callback-only review flag');
Protocol::fetch($s, $a, $t);
check($t->requests[0]->headers()['Idempotency-Key'] === $t->requests[1]->headers()['Idempotency-Key'], 'timeout replay key');
check($t->requests[0]->body() === $t->requests[1]->body(), 'timeout replay bytes');
check(json_decode($t->requests[0]->body(), true)['amount'] === '25', 'API amount string');
Protocol::fetch($s, $saved, $t);
check($t->requests[2]->method === 'GET', 'known invoice GET');
foreach (['project_id','store_id','pay_url','api_url'] as $key) {
    $change = str_ends_with($key, '_id') ? '44444444-4444-4444-8444-444444444444' : 'https://elsewhere.example.test';
    rejects(fn () => Protocol::fetch(array_replace($s, [$key => $change]), $saved, $t), 'connection changed');
}
rejects(fn () => Protocol::fetch(array_replace($s, ['api_key' => str_repeat('c', 40)]), $a, $t), 'uncertain creation credential changed');
Protocol::fetch(array_replace($s, ['api_key' => str_repeat('c', 40)]), $saved, $t);
check(true, 'known invoice credential rotation allowed');
foreach (['invoice_id' => $s['store_id'], 'project_id' => $s['store_id'], 'store_id' => $s['project_id'], 'order_id' => 'shop:99', 'amount' => '26', 'currency' => 'USD', 'status' => 'paid', 'amount_status' => 'bad', 'sequence' => 0, 'requires_review' => 'false'] as $key => $value) {
    $t->invoice = array_replace($invoice, [$key => $value]);
    rejects(fn () => Protocol::fetch($s, $saved, $t), 'invalid ' . $key);
}
$t->invoice = $invoice;
foreach (['https://attacker.example.test/invoice/' . $invoice['invoice_id'], $s['pay_url'] . '/invoice/' . $invoice['invoice_id'] . '?a=1', $s['pay_url'] . '/other'] as $url) {
    $t->url = $url; rejects(fn () => Protocol::fetch($s, $saved, $t), 'redirect allowlist');
}
$t->url = $s['pay_url'] . '/invoice/' . $invoice['invoice_id'];
foreach ([401,403,409,429,500,503] as $status) {
    $t->status = $status;
    try { Protocol::fetch($s, $saved, $t); throw new RuntimeException('Accepted error'); }
    catch (WhollyCrypto\PrestaShop\Sdk\Exception\ApiException $e) { check(!str_contains(Protocol::error($e), 'PRIVATE'), 'redacted error'); }
}
$p = array_replace($invoice, ['payload_version' => 2, 'status' => 'settled', 'amount_status' => 'paid', 'sequence' => 3,
    'event_type' => 'invoice.settled', 'event_id' => '55555555-5555-4555-8555-555555555555']);
$headers = ['Wholly-Event-Id' => $p['event_id'], 'Wholly-Delivery-Id' => '66666666-6666-4666-8666-666666666666'];
function signed(array $p, array $headers, string $secret, int $offset = 0): array {
    $raw = json_encode($p, JSON_THROW_ON_ERROR); $time = time() + $offset;
    $headers['Wholly-Signature'] = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $raw, $secret);
    return [$raw, $headers];
}
[$raw, $h] = signed($p, $headers, $s['ipn_secret']);
check(Protocol::notification($saved, $raw, $h, $s['ipn_secret']) === $p, 'verified callback');
rejects(fn () => Protocol::notification($saved, $raw . ' ', $h, $s['ipn_secret']), 'mutated body');
rejects(fn () => Protocol::notification($saved, $raw, $h, str_repeat('c', 40)), 'wrong secret');
rejects(fn () => Protocol::notification($saved, $raw, [], $s['ipn_secret']), 'missing headers');
foreach (['order_id' => 'shop:99', 'amount' => '24', 'store_id' => $s['project_id'], 'payload_version' => 1] as $key => $value) {
    [$bad, $badH] = signed(array_replace($p, [$key => $value]), $headers, $s['ipn_secret']);
    rejects(fn () => Protocol::notification($saved, $bad, $badH, $s['ipn_secret']), 'signed but mismatched ' . $key);
}
[$old, $oldH] = signed($p, $headers, $s['ipn_secret'], -400);
rejects(fn () => Protocol::notification($saved, $old, $oldH, $s['ipn_secret']), 'expired signature');
foreach ([['new','none',false,'pending'], ['processing','paid',false,'pending'], ['processing','partial',false,'pending'], ['settled','paid',false,'paid'], ['settled','overpaid',false,'paid'], ['settled','paid',true,'review'], ['expired','none',false,'expired'], ['expired','partial',false,'review'], ['invalid','paid',false,'review'], ['cancelled','none',false,'expired']] as [$status,$amount,$review,$decision]) {
    check(Protocol::decision(array_replace($saved, ['status'=>$status,'amount_status'=>$amount,'review'=>$review])) === $decision, 'state decision');
}
$t->status = 200; $t->invoice = array_replace($invoice, ['timing_status'=>'late','status'=>'settled','amount_status'=>'paid']);
check(Protocol::decision(Protocol::fetch($s, $saved, $t)) === 'review', 'late settlement review');
$t->invoice = array_replace($invoice, ['timing_status'=>'on_time','status'=>'settled','amount_status'=>'overpaid']);
check(Protocol::decision(Protocol::fetch($s, $saved, $t)) === 'review', 'API overpayment derives review without a callback flag');
$t->invoice = array_replace($invoice, ['status'=>'settled','amount_status'=>'paid']);
check(Protocol::decision(Protocol::fetch($s, $saved, $t)) === 'paid', 'real invoice-detail shape settles without callback-only fields');
$t->invoice['timing_status'] = 'unknown';
rejects(fn () => Protocol::fetch($s, $saved, $t), 'unknown timing state');
echo "Protocol: $checks assertions passed on PHP " . PHP_VERSION . "\n";
