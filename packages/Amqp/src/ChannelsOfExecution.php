<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Closure;
use Interop\Queue\Context;

/**
 * licence Apache-2.0
 */
final class ChannelsOfExecution
{
    /** @var Context[] */
    public array $contexts = [];

    /**
     * @param Closure(Context[]): void $keepOpenForNextExecution
     */
    public function __construct(private Closure $keepOpenForNextExecution)
    {
    }

    public function __destruct()
    {
        ($this->keepOpenForNextExecution)($this->contexts);
    }
}
