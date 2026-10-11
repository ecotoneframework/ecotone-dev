<?php

namespace Ecotone\Api\DataProtection;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
