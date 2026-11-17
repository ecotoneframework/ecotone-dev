<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;

/**
 * licence Apache-2.0
 */
final class InterceptedChainDefinition extends Definition
{
    public const BEFORE = 'before';
    public const HANDLER = 'handler';
    public const AFTER = 'after';
    public const STEP = 'step';

    /**
     * @param list<Definition|Reference> $processors
     * @param list<string> $roles
     */
    public function __construct(array $processors, public readonly array $roles)
    {
        parent::__construct(ChainedMessageProcessor::class, [$processors]);
    }
}
