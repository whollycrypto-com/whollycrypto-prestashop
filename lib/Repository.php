<?php
declare(strict_types=1);

namespace WhollyCrypto\PrestaShop;

/** Thin adapter over the platform connection: locks and transactions use that same connection. */
final class Repository
{
    public function __construct(private \Closure $execute, private \Closure $rows, private \Closure $escape, private string $table, private array $nativeTables = [])
    {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }
    }

    public function install(): void
    {
        $this->assertTransactional($this->nativeTables);
        $this->run("CREATE TABLE IF NOT EXISTS `{$this->table}` (
            order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            invoice_id CHAR(36) NULL, record LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL, UNIQUE KEY invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->assertTransactional([$this->table]);
    }

    /** Refuse non-transactional storage instead of risking a partially applied payment. */
    public function assertTransactional(?array $tables = null): void
    {
        $tables = $tables ?? array_merge([$this->table], $this->nativeTables);
        if (!$tables) {
            return;
        }
        foreach ($tables as $table) {
            if (!is_string($table) || !preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) {
                throw new \InvalidArgumentException('Invalid table name.');
            }
        }
        $quoted = "'" . implode("','", array_unique($tables)) . "'";
        $rows = ($this->rows)("SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($quoted)");
        $engines = array_column($rows ?: [], 'engine', 'name');
        foreach ($tables as $table) {
            if (strcasecmp((string) ($engines[$table] ?? ''), 'InnoDB') !== 0) {
                throw new \RuntimeException('Wholly Crypto requires InnoDB payment and order tables. Ask your database administrator before enabling payments.');
            }
        }
    }

    public function load(int $order): ?array
    {
        $rows = ($this->rows)("SELECT record FROM `{$this->table}` WHERE order_id=" . $order);
        return $rows ? json_decode($rows[0]['record'], true, 64, JSON_THROW_ON_ERROR) : null;
    }

    public function save(array $a): void
    {
        $json = ($this->escape)(json_encode($a, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $invoice = $a['invoice_id'] ? "'" . ($this->escape)(Protocol::uuid($a['invoice_id'])) . "'" : 'NULL';
        $order = (int) $a['order_id'];
        if ($this->load($order) !== null) {
            $this->run("UPDATE `{$this->table}` SET invoice_id=$invoice,record='$json',updated_at=UTC_TIMESTAMP() WHERE order_id=$order");
        } else {
            $this->run("INSERT INTO `{$this->table}` (order_id,invoice_id,record,updated_at) VALUES ($order,$invoice,'$json',UTC_TIMESTAMP())");
        }
    }

    public function locked(int $order, callable $callback): mixed
    {
        $name = 'wc_' . substr(hash('sha256', $this->table . ':' . $order), 0, 48);
        $rows = ($this->rows)("SELECT GET_LOCK('$name',0) AS acquired");
        if ((int) ($rows[0]['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('This order is being processed. Retry shortly.');
        }
        try {
            return $callback();
        } finally {
            ($this->rows)("SELECT RELEASE_LOCK('$name')");
        }
    }

    public function transaction(callable $callback): mixed
    {
        $this->assertTransactional();
        $this->run('START TRANSACTION');
        try {
            $result = $callback();
            $this->run('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->run('ROLLBACK');
            throw $e;
        }
    }

    private function run(string $sql): void
    {
        if (($this->execute)($sql) === false) {
            throw new \RuntimeException('Payment storage failed.');
        }
    }
}
