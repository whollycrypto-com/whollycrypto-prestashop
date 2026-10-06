<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WhollyCrypto\\PrestaShop\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $name = substr($class, strlen($prefix));
    $base = __DIR__;
    if (strncmp($name, 'Sdk\\', 4) === 0) {
        $base = dirname(__DIR__) . '/vendor/whollycrypto/src';
        $name = substr($name, 4);
    }
    if (!preg_match('/\A[A-Za-z0-9_\\\\]+\z/D', $name)) {
        return;
    }
    $file = $base . '/' . str_replace('\\', '/', $name) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
