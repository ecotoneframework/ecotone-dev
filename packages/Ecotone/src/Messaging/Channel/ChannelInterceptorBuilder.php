<?php

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

/**
 * Interface ChannelInterceptorBuilder
 * @package Ecotone\Messaging\Channel
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
interface ChannelInterceptorBuilder extends CompilableBuilder
{
    /**
     * @return string
     */
    public function relatedChannelName(): string;

    /**
     * It returns, internal reference objects that will be called during handling method
     *
     * @param InterfaceToCallRegistry $interfaceToCallRegistry
     * @return InterfaceToCall[]
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable;

    /**
     * @return int
     */
    public function getPrecedence(): int;
}
