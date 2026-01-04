<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Manager;

use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\ConsoleParameterOption;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;

/**
 * Console command for deleting message channels.
 *
 * licence Apache-2.0
 */
class ChannelDeleteCommand
{
    public function __construct(
        private ChannelSetupManager $channelSetupManager
    ) {
    }

    #[ConsoleCommand('ecotone:migration:channel:delete')]
    public function delete(
        ?string $channelName = null,
        #[ConsoleParameterOption] bool $force = false,
    ): ?ConsoleCommandResultSet {
        // If specific channel name provided
        if ($channelName !== null) {
            if (!$force) {
                return ConsoleCommandResultSet::create(
                    ['Channel', 'Warning'],
                    [[$channelName, 'Would be deleted (use --force to confirm)']]
                );
            }

            $this->channelSetupManager->delete($channelName);
            return ConsoleCommandResultSet::create(
                ['Channel', 'Status'],
                [[$channelName, 'Deleted']]
            );
        }

        // Show all channels
        $channelNames = $this->channelSetupManager->getChannelNames();

        if (count($channelNames) === 0) {
            return ConsoleCommandResultSet::create(
                ['Status'],
                [['No message channels registered for deletion.']]
            );
        }

        if (!$force) {
            return ConsoleCommandResultSet::create(
                ['Channel', 'Warning'],
                array_map(fn (string $channel) => [$channel, 'Would be deleted (use --force to confirm)'], $channelNames)
            );
        }

        $this->channelSetupManager->deleteAll();
        return ConsoleCommandResultSet::create(
            ['Channel', 'Status'],
            array_map(fn (string $channel) => [$channel, 'Deleted'], $channelNames)
        );
    }
}

