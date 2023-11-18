<?php

namespace App\MultiTenancy\RateLimitWithDelay;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\CommandBus;

class EmailCampaingSender
{
    public const BATCH_LIMIT = 2;
    public const DELAY_IN_MILLISECONDS = 1000;

    #[Asynchronous(Configuration::ASYNCHRONOUS_MESSAGES)]
    #[CommandHandler(endpointId:"campainEmailSender")]
    #[AddHeaderPresend]
    public function perform(SendEmailCampaing $command, CommandBus $commandBus, EmailSender $emailSender) : void
    {
        for ($emailCount = 0; $emailCount < count($command->emails); $emailCount++) {
            if ($emailCount >= self::BATCH_LIMIT) {
                $commandBus->send(
                    new SendEmailCampaing(array_slice($command->emails, $emailCount)),
                    metadata: [
                        MessageHeaders::DELIVERY_DELAY => self::DELAY_IN_MILLISECONDS
                    ]
                );

                return;
            }

            $emailSender->send($command->emails[$emailCount]);
        }
    }
}