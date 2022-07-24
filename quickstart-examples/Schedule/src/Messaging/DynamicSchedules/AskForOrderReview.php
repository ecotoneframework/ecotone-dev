<?php

namespace App\Schedule\Messaging\DynamicSchedules;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;

class AskForOrderReview
{
    #[Asynchronous("orders")]
    #[CommandHandler("askForOrderReview", endpointId: "askForOrderReviewEndpoint")]
    public function askForOrderReview(string $orderId): void
    {
        echo sprintf("We sent notification to user after 5 seconds to ask him for review of " . $orderId);
    }
}