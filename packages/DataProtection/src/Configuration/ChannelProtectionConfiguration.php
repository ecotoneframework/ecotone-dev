<?php

namespace Ecotone\DataProtection\Configuration;

use Ecotone\Messaging\Support\Assert;

class ChannelProtectionConfiguration
{
    private array $sensitiveHeaders = [];

    private function __construct(private string $channelName, private ?string $encryptionKey = null)
    {
    }

    public static function create(string $channelName, ?string $encryptionKey = null): self
    {
        return new self($channelName, $encryptionKey);
    }

    public function channelName(): string
    {
        return $this->channelName;
    }

    public function obfuscatorConfig(): ObfuscatorConfig
    {
        return new ObfuscatorConfig($this->encryptionKey, $this->sensitiveHeaders);
    }

    public function withSensitiveHeaders(array $sensitiveHeaders): self
    {
        Assert::allStrings($sensitiveHeaders, 'Sensitive Headers should be array of strings');

        $config = clone $this;
        $config->sensitiveHeaders = array_merge($this->sensitiveHeaders, $sensitiveHeaders);

        return $config;
    }

    public function withSensitiveHeader(string $sensitiveHeader): self
    {
        $config = clone $this;
        $config->sensitiveHeaders[] = $sensitiveHeader;

        return $config;
    }
}
