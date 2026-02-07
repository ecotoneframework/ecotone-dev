<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection\Configuration;

use Defuse\Crypto\Key;
use Ecotone\Messaging\Support\Assert;

class DataProtectionConfiguration
{
    /**
     * @param array<string, Key> $keys
     */
    private function __construct(private array $keys, private string $defaultKey)
    {
    }

    public static function create(string $name, Key $key): self
    {
        return new self(keys: [$name => $key], defaultKey: $name);
    }

    public function withKey(string $name, Key $key, bool $asDefault = false): self
    {
        Assert::keyNotExists($this->keys, $name, sprintf('Encryption key name `%s` already exists', $name));

        $config = clone $this;
        $config->keys[$name] = $key;

        if ($asDefault) {
            $config->defaultKey = $name;
        }

        return $config;
    }

    public function keyName(?string $name): string
    {
        return array_key_exists($name, $this->keys) ? $name : $this->defaultKey;
    }

    public function keys(): array
    {
        return $this->keys;
    }
}
