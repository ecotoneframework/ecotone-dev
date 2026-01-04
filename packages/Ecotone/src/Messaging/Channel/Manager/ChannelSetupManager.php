<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Manager;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * Manages channel setup and teardown for all registered channel managers.
 * Similar to DatabaseSetupManager but for message channels.
 *
 * licence Apache-2.0
 */
class ChannelSetupManager implements DefinedObject
{
    /**
     * @param ChannelManager[] $channelManagers
     */
    public function __construct(
        private array $channelManagers = [],
    ) {
    }

    /**
     * @return string[] List of channel names
     */
    public function getChannelNames(): array
    {
        return array_map(
            fn (ChannelManager $manager) => $manager->getChannelName(),
            $this->channelManagers
        );
    }

    /**
     * Initialize all channels
     */
    public function initializeAll(): void
    {
        foreach ($this->channelManagers as $manager) {
            $manager->initialize();
        }
    }

    /**
     * Initialize specific channel by name
     */
    public function initialize(string $channelName): void
    {
        $manager = $this->findManager($channelName);
        $manager->initialize();
    }

    /**
     * Delete all channels
     */
    public function deleteAll(): void
    {
        foreach ($this->channelManagers as $manager) {
            $manager->delete();
        }
    }

    /**
     * Delete specific channel by name
     */
    public function delete(string $channelName): void
    {
        $manager = $this->findManager($channelName);
        $manager->delete();
    }

    /**
     * Returns initialization status for each channel
     * @return array<string, bool> Map of channel name to initialization status
     */
    public function getInitializationStatus(): array
    {
        $status = [];

        foreach ($this->channelManagers as $manager) {
            $status[$manager->getChannelName()] = $manager->isInitialized();
        }

        return $status;
    }

    private function findManager(string $channelName): ChannelManager
    {
        foreach ($this->channelManagers as $manager) {
            if ($manager->getChannelName() === $channelName) {
                return $manager;
            }
        }

        throw new \InvalidArgumentException("Channel manager not found for channel: {$channelName}");
    }

    public function getDefinition(): Definition
    {
        $channelManagerDefinitions = array_map(
            fn (ChannelManager $manager) => $manager->getDefinition(),
            $this->channelManagers
        );

        return new Definition(
            self::class,
            [$channelManagerDefinitions]
        );
    }
}

