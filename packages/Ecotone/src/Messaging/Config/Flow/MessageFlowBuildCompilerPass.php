<?php

/*
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

final class MessageFlowBuildCompilerPass implements CompilerPass
{
    public function __construct(
        private Configuration $configuration,
        private InterfaceToCallRegistry $interfaceToCallRegistry,
        private MessageFlowGraph $declared,
    ) {
    }

    public function process(ContainerBuilder $builder): void
    {
        $builder->register(MessageFlowBuildGraphBuilder::class, new Definition(MessageFlowBuildGraphBuilder::class, [
            new Reference(MessageFlowGraphBuilder::class),
            MessageFlowBuildWiringDiscovery::discover(configuration: $this->configuration, interfaceToCallRegistry: $this->interfaceToCallRegistry, declared: $this->declared)->getDefinition(),
        ]));
        $ledger = MessageFlowLedgerDiscovery::discover($this->configuration, $this->interfaceToCallRegistry);
        $builder->register(MessageFlowBuild::class, (new MessageFlowBuild($ledger['ledger'], MessageFlowChainReader::read(builder: $builder, configuration: $this->configuration, interfaceToCallRegistry: $this->interfaceToCallRegistry, handlerNames: $ledger['handlerNames'])))->getDefinition());
    }
}
