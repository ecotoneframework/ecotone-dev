<?php

namespace App\Schedule\Messaging\DynamicSchedules;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;

class AskForOrderReview
{
    #[Asynchronous("orders")]
    #[CommandHandler("askForOrderReview", endpointId: "askForOrderReviewEndpoint")]
    public function askForOrderReview(string $orderId): void
    {
        echo sprintf("We sent notification to user after 5 seconds to ask him for review of " . $orderId);
    }
}