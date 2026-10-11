<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

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
class SpecificHeaderResultMessageConverter implements ResultToMessageConverter
{
    public function __construct(private string $headerName)
    {
    }

    public function convertToMessage(Message $requestMessage, mixed $result): ?Message
    {
        if (is_null($result)) {
            return null;
        }

        Assert::isFalse($result instanceof Message, 'Message should not be returned when setting specific header in ' . $this->headerName);

        return MessageBuilder::fromMessage($requestMessage)
            ->setHeader($this->headerName, $result)
            ->build();
    }
}
