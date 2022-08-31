<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedMessage\Publisher;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\DistributedBus;
use Test\Ecotone\Amqp\Fixture\DistributedMessage\Receiver\TicketServiceMessagingConfiguration;
use Test\Ecotone\Amqp\Fixture\DistributedMessage\Receiver\TicketServiceReceiver;

class UserService
{
    public const CHANGE_BILLING_DETAILS = 'changeBillingDetails';

    #[CommandHandler(self::CHANGE_BILLING_DETAILS)]
    public function changeBillingDetails(#[Reference] DistributedBus $commandBus)
    {
        $commandBus->sendMessage(
            TicketServiceMessagingConfiguration::SERVICE_NAME,
            TicketServiceReceiver::CREATE_TICKET_ENDPOINT,
            'User changed billing address'
        );
    }
}
