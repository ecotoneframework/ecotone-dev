<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Publisher;

use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Gateway\DistributedBus;
use Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Receiver\TicketServiceMessagingConfiguration;
use Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Receiver\TicketServiceReceiver;

/**
 * licence Apache-2.0
 */
class UserService
{
    public const CHANGE_BILLING_DETAILS = 'changeBillingDetails';

    #[CommandHandler(self::CHANGE_BILLING_DETAILS)]
    public function changeBillingDetails(#[Reference] DistributedBus $distributedBus)
    {
        $distributedBus->sendCommand(
            TicketServiceMessagingConfiguration::SERVICE_NAME,
            TicketServiceReceiver::CREATE_TICKET_ENDPOINT,
            'User changed billing address'
        );
    }
}
