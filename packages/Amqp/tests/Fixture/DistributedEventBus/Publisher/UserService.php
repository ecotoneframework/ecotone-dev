<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedEventBus\Publisher;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\DistributedBus;

class UserService
{
    public const CHANGE_BILLING_DETAILS = 'changeBillingDetails';
    public const BILLING_DETAILS_WERE_CHANGED = 'userService.billingDetailsWereChanged';

    #[CommandHandler(self::CHANGE_BILLING_DETAILS)]
    public function changeBillingDetails(#[Reference] DistributedBus $commandBus)
    {
        $commandBus->publishEvent(
            self::BILLING_DETAILS_WERE_CHANGED,
            'ticket was created'
        );
    }
}
