<?php

declare(strict_types=1);

namespace Ecotone\GDPR\Key;

use Defuse\Crypto\Key;

final readonly class StaticEncryptionKeyProvider implements EncryptionKeyProvider
{
    private function __construct(private Key $key)
    {
    }

    public static function create(Key $key): self
    {
        return new self($key);
    }

    public static function random(): self
    {
        return new self(Key::createNewRandomKey());
    }

    public function key(): Key
    {
        return $this->key;
    }
}
