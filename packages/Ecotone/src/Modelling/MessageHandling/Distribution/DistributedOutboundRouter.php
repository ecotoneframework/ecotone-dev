<?php

declare(strict_types=1);

namespace Ecotone\Modelling\MessageHandling\Distribution;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MessageChannelConfiguration;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\Api\Distribution\DistributedBusHeader;
use Ecotone\Modelling\Api\Distribution\DistributedServiceMap;

/**
 * licence Enterprise
 */
final class DistributedOutboundRouter
{
    public function __construct(
        private DistributedServiceMap $distributedServiceMap,
        private MessageChannelConfiguration $messageChannelConfiguration,
        private string $thisServiceName
    )
    {

    }

    public function route(
        #[Header(DistributedBusHeader::DISTRIBUTED_PAYLOAD_TYPE)]
        string  $payloadType,
        #[Header(DistributedBusHeader::DISTRIBUTED_TARGET_SERVICE_NAME)]
        ?string $targetedServiceName,
        #[Header(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY)]
        $routingKey,
    ): array {
        if ($payloadType === 'event') {
            return $this->distributedServiceMap->getAllChannelNamesBesides($this->thisServiceName, $routingKey);
        } elseif (in_array($payloadType, ['command', 'message'])) {
            Assert::isTrue($targetedServiceName !== null, sprintf('
                Cannot send commands to shared channel - `%s`. Commands follow point-to-point semantics, and shared channels are reserved for events only.
                Change your channel to standard pollable channel.
            ', $targetedServiceName));

            $targetChannelName = $this->distributedServiceMap->getChannelNameFor($targetedServiceName);

            Assert::isFalse($this->messageChannelConfiguration->isShared($targetChannelName), "Can't send command to shared channel, commands follow point to point semantics. Please use standard pollable channel instead.");
            return [$targetChannelName];
        } else {
            throw InvalidArgumentException::create("Trying to call distributed command handler for payload type {$payloadType} and allowed are event/command/message");
        }
    }
}
