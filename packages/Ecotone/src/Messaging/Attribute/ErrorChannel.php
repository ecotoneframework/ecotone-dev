<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Attribute;

use Attribute;
use Ecotone\Messaging\Support\Assert;

/**
 * licence Enterprise
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ErrorChannel
{
    /**
     * @param string $errorChannelName Name of the error channel to send Message too
     * @param string|null $replyChannelName If Message will be stored in Dead Letter, through which channel it will be replied
     */
    public function __construct(
        public readonly string   $errorChannelName,
        public readonly  ?string $replyChannelName = null
    )
    {
        Assert::notNullAndEmpty($errorChannelName, 'Channel name can not be empty string');
    }
}
