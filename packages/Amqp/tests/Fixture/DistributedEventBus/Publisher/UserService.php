<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedEventBus\Publisher;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Parameter\Header;
use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Api\Gateway\DistributedBus;

/**
 * licence Apache-2.0
 */
class UserService
{
    public const CHANGE_BILLING_DETAILS = 'changeBillingDetails';
    public const BILLING_DETAILS_WERE_CHANGED = 'userService.billing.DetailsWereChanged';

    #[CommandHandler(self::CHANGE_BILLING_DETAILS)]
    public function changeBillingDetails(
        #[Reference] DistributedBus $distributedBus,
        #[Header('shouldThrowException')] bool $shouldThrowException = false,
    ) {
        $distributedBus->publishEvent(
            self::BILLING_DETAILS_WERE_CHANGED,
            'ticket was created',
            metadata: [
                'shouldThrowException' => $shouldThrowException,
            ]
        );
    }
}
