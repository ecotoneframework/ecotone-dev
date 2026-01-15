<?php

namespace Ecotone\DataProtection\Obfuscator;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

class Obfuscator implements DefinedObject
{
    private bool $obfuscateAll;

    public function __construct(
        private array $sensitive,
        private array $scalar,
        private Key $encryptionKey
    ) {
        $this->obfuscateAll = $sensitive === [];
    }

    public function encrypt(string $json): string
    {
        $payload = json_decode($json, true);
        $sensitiveParameters = $this->resolveSensitiveParameters($payload);

        foreach ($sensitiveParameters as $key) {
            $value = in_array($key, $this->scalar) ? $payload[$key] : json_encode($payload[$key]);

            $payload[$key] = base64_encode(Crypto::encrypt($value, $this->encryptionKey));
        }

        return json_encode($payload);
    }

    public function decrypt(string $json): string
    {
        $payload = json_decode($json, true);
        $sensitiveParameters = $this->resolveSensitiveParameters($payload);

        foreach ($sensitiveParameters as $key) {
            $value = Crypto::decrypt(base64_decode($payload[$key]), $this->encryptionKey);

            $payload[$key] = in_array($key, $this->scalar) ? $value : json_decode($value, true);
        }

        return json_encode($payload);
    }

    private function resolveSensitiveParameters(array $payload): array
    {
        if ($this->obfuscateAll) {
            return array_keys($payload);
        }

        return array_filter($this->sensitive, static fn (string $key) => array_key_exists($key, $payload));
    }

    public function getDefinition(): Definition
    {
        return Definition::createFor(self::class, [$this->sensitive, $this->scalar, $this->encryptionKey]);
    }
}
