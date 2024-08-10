<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Test\Ecotone\Messaging\Fixture\InterceptorsOrdering\InterceptorOrderingStack;

final class OutputHandler
{
    #[InternalHandler(inputChannelName: 'internal-channel')]
    public function output(#[Reference] InterceptorOrderingStack $stack): mixed
    {
        $stack->add('command-output-channel');

        return 'something';
    }
}