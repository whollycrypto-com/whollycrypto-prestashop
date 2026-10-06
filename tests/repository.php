<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/autoload.php';
use WhollyCrypto\PrestaShop\Repository;

// Isolated synthetic database only. The random table is created and removed by this test.
$dsn = getenv('WHOLLY_TEST_DB_DSN');
if (!$dsn) { fwrite(STDERR, "Set WHOLLY_TEST_DB_DSN to an isolated MySQL test database.\n"); exit(2); }
$db = new PDO($dsn, getenv('WHOLLY_TEST_DB_USER') ?: 'root', getenv('WHOLLY_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$table = 'wc_test_' . bin2hex(random_bytes(6));
$make = static fn (PDO $c) => new Repository(static fn ($q) => $c->exec($q), static fn ($q) => $c->query($q)->fetchAll(PDO::FETCH_ASSOC), static fn ($s) => substr($c->quote($s), 1, -1), $table);
$repo = $make($db); $repo->install();
function verify($ok, $message) { if (!$ok) throw new RuntimeException($message); }
try {
    $a = ['order_id'=>1, 'invoice_id'=>null, 'attempt_key'=>'persistent-key', 'payload'=>['amount'=>'25.00']];
    $repo->locked(1, function () use ($repo,$a) { $repo->save($a); });
    verify($repo->load(1) === $a, 'record persisted');
    try { $repo->transaction(function () use ($repo,$a) { $repo->save(array_replace($a, ['attempt_key'=>'wrong'])); throw new RuntimeException('rollback'); }); } catch (RuntimeException) {}
    verify($repo->load(1) === $a, 'rollback preserves original key');
    $a['invoice_id'] = '33333333-3333-4333-8333-333333333333'; $repo->save($a);
    try { $repo->save(array_replace($a,['order_id'=>2])); throw new LogicException('Duplicate accepted'); } catch (PDOException) {}
    verify($repo->load(1) === $a && $repo->load(2) === null, 'duplicate invoice cannot overwrite order');
    $other = new PDO($dsn, getenv('WHOLLY_TEST_DB_USER') ?: 'root', getenv('WHOLLY_TEST_DB_PASSWORD') ?: '');
    $repo->locked(1, function () use ($make,$other) { try { $make($other)->locked(1, fn () => null); throw new LogicException('Lock ignored'); } catch (RuntimeException $e) { verify(!($e instanceof LogicException), 'concurrent lock refused'); } });
    $make($other)->locked(1, fn () => null);
    $db->exec("ALTER TABLE `$table` ENGINE=MyISAM");
    $ran = false;
    try { $repo->transaction(function () use (&$ran) { $ran = true; }); } catch (RuntimeException $e) { verify(str_contains($e->getMessage(), 'InnoDB'), 'unsafe engine is explained'); }
    verify(!$ran, 'non-transactional storage refused before mutations');
    $db->exec("ALTER TABLE `$table` ENGINE=InnoDB");
    $repo->transaction(fn () => null);
    echo "Repository: persistence, rollback, invoice uniqueness, cross-connection locking and storage safety passed.\n";
} finally { $db->exec("DROP TABLE `$table`"); }
