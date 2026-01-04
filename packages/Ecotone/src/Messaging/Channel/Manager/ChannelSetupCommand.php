<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Manager;

use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\ConsoleParameterOption;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;

/**
 * Console command for setting up message channels.
 *
 * licence Apache-2.0
 */
class ChannelSetupCommand
{
    public function __construct(
        private ChannelSetupManager $channelSetupManager
    ) {
    }

    #[ConsoleCommand('ecotone:migration:channel:setup')]
    public function setup(
        ?string $channelName = null,
        #[ConsoleParameterOption] bool $initialize = false,
    ): ?ConsoleCommandResultSet {
        // If specific channel name provided
        if ($channelName !== null) {
            if ($initialize) {
                $this->channelSetupManager->initialize($channelName);
                return ConsoleCommandResultSet::create(
                    ['Channel', 'Status'],
                    [[$channelName, 'Initialized']]
                );
            }

            $status = $this->channelSetupManager->getInitializationStatus();
            return ConsoleCommandResultSet::create(
                ['Channel', 'Initialized'],
                [[$channelName, $status[$channelName] ? 'Yes' : 'No']]
            );
        }

        // Show all channels
        $channelNames = $this->channelSetupManager->getChannelNames();

        if (count($channelNames) === 0) {
            return ConsoleCommandResultSet::create(
                ['Status'],
                [['No message channels registered for setup.']]
            );
        }

        if ($initialize) {
            $this->channelSetupManager->initializeAll();
            return ConsoleCommandResultSet::create(
                ['Channel', 'Status'],
                array_map(fn (string $channel) => [$channel, 'Initialized'], $channelNames)
            );
        }

        // Show status
        $initializationStatus = $this->channelSetupManager->getInitializationStatus();
        $rows = [];
        foreach ($channelNames as $channelName) {
            $isInitialized = $initializationStatus[$channelName] ?? false;
            $rows[] = [$channelName, $isInitialized ? 'Yes' : 'No'];
        }

        return ConsoleCommandResultSet::create(['Channel', 'Initialized'], $rows);
    }
}

