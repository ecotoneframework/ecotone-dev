<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Support\LicensingException;

final class OpenCoreMessageFlowGraphBuilder implements MessageFlowGraphBuilder
{
    public function build(): MessageFlowGraph
    {
        throw LicensingException::create('The message flow graph (ConfiguredMessagingSystem::getMessageFlowGraph() and the ecotone:flow:dump console command) is part of Ecotone Enterprise. Provide your licence key as `licenceKey` in the Symfony, Laravel or Tempest Ecotone configuration, or as the `licenceKey:` argument of EcotoneLite, or start a trial at https://ecotone.tech/pricing. See https://docs.ecotone.tech/enterprise for details.');
    }
}
