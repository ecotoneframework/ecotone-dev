<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Recoverability;

/**
 * licence Apache-2.0
 */
final class ReplayChannels
{
    /**
     * @param array<string, string> $replayChannelNames
     */
    public function __construct(private array $replayChannelNames)
    {
    }

    /**
     * @param object[] $extensionObjects
     * @return array<string, string>
     */
    public static function declaredBy(array $extensionObjects): array
    {
        $replayChannelNames = [];
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof DeclaresReplayChannel) {
                $replayChannelNames[$extensionObject->getMessageChannelName()] = $extensionObject->replayChannelName();
            }
        }

        return $replayChannelNames;
    }

    public function of(string $channelName): string
    {
        return $this->replayChannelNames[$channelName] ?? $channelName;
    }
}
