<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Recoverability;

use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;

/**
 * licence Apache-2.0
 */
final class DeadLetterChannels
{
    /**
     * @param array<string, string> $deadLetterChannelNames
     */
    public function __construct(private array $deadLetterChannelNames)
    {
    }

    /**
     * @param object[] $extensionObjects
     * @return array<string, string>
     */
    public static function configuredBy(array $extensionObjects): array
    {
        $deadLetterChannelNames = [];
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof ErrorHandlerConfiguration && $extensionObject->deadLetterChannel !== null) {
                $deadLetterChannelNames[$extensionObject->getErrorChannelName()] = $extensionObject->deadLetterChannel;
            }
        }

        return $deadLetterChannelNames;
    }

    public function of(string $errorChannelName): string
    {
        return $this->deadLetterChannelNames[$errorChannelName] ?? $errorChannelName;
    }
}
