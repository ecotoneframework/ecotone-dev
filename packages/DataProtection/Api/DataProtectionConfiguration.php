<?php

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */

namespace Ecotone\Api\DataProtection;

use Ecotone\Messaging\Support\Assert;

final class DataProtectionConfiguration
{
    /**
     * @param array<string, Key> $keys
     */
    private function __construct(public readonly array $keys, public readonly string $defaultKey)
    {
    }

    public static function create(string $name, Key $key): self
    {
        return new self(keys: [$name => $key], defaultKey: $name);
    }

    public function withKey(string $name, Key $key, bool $asDefault = false): self
    {
        Assert::keyNotExists($this->keys, $name, sprintf('Encryption key name `%s` already exists', $name));

        return new self(...[...get_object_vars($this), 'keys' => array_replace($this->keys, [$name => $key]), 'defaultKey' => $asDefault ? $name : $this->defaultKey]);
    }

    public function keyName(?string $name): string
    {
        return $name !== null && array_key_exists($name, $this->keys) ? $name : $this->defaultKey;
    }
}
