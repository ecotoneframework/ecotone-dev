<?php

namespace Ecotone\DataProtection\Configuration;

use Defuse\Crypto\Key;
use Ecotone\Messaging\Support\Assert;

class DataProtectionConfiguration
{
    private function __construct(private array $keys, private Key $defaultKey)
    {
    }

    public static function create(string $name, Key $key): self
    {
        return new self(keys: [$name => $key], defaultKey: $key);
    }

    public function withKey(string $name, Key $key, bool $asDefault = false): self
    {
        Assert::keyNotExists($this->keys, $name, sprintf('Encryption key name `%s` already exists', $name));

        $config = clone $this;
        $config->keys[$name] = $key;

        if ($asDefault) {
            $config->defaultKey = $key;
        }

        return $config;
    }

    public function key(?string $name): Key
    {
        return $this->keys[$name] ?? $this->defaultKey;
    }
}
