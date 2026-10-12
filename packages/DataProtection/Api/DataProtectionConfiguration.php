<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */

namespace Ecotone\Api\DataProtection;

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
        return $name !== null && array_key_exists($name, $this->keys) ? $name : $this->defaultKey;
    }

    public function keys(): array
    {
        return $this->keys;
    }
}
