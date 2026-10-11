<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DeliveryConfirmation;

use Ecotone\Api\Messaging\Message;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class FailedDelivery
{
    public function __construct(
        private Message $message,
        private string $failureReason,
        private string $channelName,
    ) {
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getFailureReason(): string
    {
        return $this->failureReason;
    }
}
