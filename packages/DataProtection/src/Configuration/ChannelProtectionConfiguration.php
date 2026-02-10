<?php

namespace Ecotone\DataProtection\Configuration;

/**
 * licence Enterprise
 */
class ChannelProtectionConfiguration
{
    private function __construct(
        private string $channelName,
        private ?string $encryptionKey,
        private bool $isPayloadSensitive,
        private array $sensitiveHeaders,
    ) {
    }

    public static function create(string $channelName, ?string $encryptionKey = null, $isPayloadSensitive = true, array $sensitiveHeaders = []): self
    {
        return new self($channelName, $encryptionKey, $isPayloadSensitive, $sensitiveHeaders);
    }

    public function channelName(): string
    {
        return $this->channelName;
    }

    public function messageEncryptionConfig(): MessageEncryptionConfig
    {
        return new MessageEncryptionConfig($this->encryptionKey, $this->isPayloadSensitive, $this->sensitiveHeaders);
    }

    public function withSensitivePayload(bool $isPayloadSensitive): self
    {
        $config = clone $this;
        $config->isPayloadSensitive = $isPayloadSensitive;

        return $config;
    }

    public function withSensitiveHeader(string $sensitiveHeader): self
    {
        $config = clone $this;
        $config->sensitiveHeaders[] = $sensitiveHeader;

        return $config;
    }
}
