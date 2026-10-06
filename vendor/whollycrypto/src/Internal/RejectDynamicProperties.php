<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk\Internal;

/** @internal Read-only public views over private state on PHP 7.4 and PHP 8. */
trait RejectDynamicProperties
{
    private bool $immutableInitialized = false;

    private function initializeImmutable(): void
    {
        if ($this->immutableInitialized) {
            throw new \Error('Cannot reinitialize an immutable SDK value.');
        }
        $this->immutableInitialized = true;
    }

    /** @return mixed */
    public function __get(string $name)
    {
        if (!in_array($name, self::READABLE_PROPERTIES, true)) {
            throw new \Error('Cannot read an inaccessible SDK property.');
        }
        return $this->$name;
    }

    public function __isset(string $name): bool
    {
        return in_array($name, self::READABLE_PROPERTIES, true) && isset($this->$name);
    }

    /** Preserve the previous public-property JSON shape, without private fields. */
    public function jsonSerialize(): array
    {
        $result = [];
        foreach (self::READABLE_PROPERTIES as $name) {
            $result[$name] = $this->$name;
        }
        return $result;
    }

    /** @param mixed $value */
    public function __set(
        string $name,
        #[\SensitiveParameter]
        $value
    ): void
    {
        throw new \Error('Cannot add or change an inaccessible property on an immutable SDK value.');
    }

    public function __unset(string $name): void
    {
        throw new \Error('Cannot remove a property from an immutable SDK value.');
    }
}
