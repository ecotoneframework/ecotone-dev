<?php

namespace Ecotone\DataProtection\Configuration;

/**
 * licence Enterprise
 */
readonly class ChannelProtectionConfiguration
{
    private function __construct(
        public string  $channelName,
        public ?string $encryptionKey,
        public bool    $isPayloadSensitive,
        public array   $sensitiveHeaders,
    ) {
    }

    public static function create(string $channelName, ?string $encryptionKey = null, $isPayloadSensitive = true, array $sensitiveHeaders = []): self
    {
        return new self($channelName, $encryptionKey, $isPayloadSensitive, $sensitiveHeaders);
    }

    public function withEncryptionKey(string $encryptionKey): self
    {
        return self::create($this->channelName, $encryptionKey, $this->isPayloadSensitive, $this->sensitiveHeaders);
    }

    public function withSensitivePayload(bool $isPayloadSensitive): self
    {
        return self::create($this->channelName, $this->encryptionKey, $isPayloadSensitive, $this->sensitiveHeaders);
    }

    public function withSensitiveHeader(string $sensitiveHeader): self
    {
        return self::create($this->channelName, $this->encryptionKey, $this->isPayloadSensitive, array_merge($this->sensitiveHeaders, [$sensitiveHeader]));
    }
}
