<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\CommandBus;

/**
 * licence Apache-2.0
 */
#[ErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL, replyChannelName: 'asyncReplyChannel')]
interface SynchronousErrorChannelWithReplyCommandBus extends CommandBus
{
}
