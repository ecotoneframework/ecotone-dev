<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Collecting;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CollectingTest extends TestCase
{
    public function test_collected_handler_without_asynchronous_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect' requires an asynchronous channel. Add #[Asynchronous('channelName')] to the handler, or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            enabledModules: [],
        );
    }

    public function test_collected_handler_without_max_size_or_max_wait_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect' needs a release limit. Set maxSize or maxWait on #[Collected], or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
        );
    }

    public function test_collected_handler_requires_an_enterprise_licence(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
            }
        };

        $this->expectExceptionMessage("Collected handler 'collect' requires an Ecotone Enterprise licence. Provide licenceKey in the framework configuration or EcotoneLite, start a trial at https://ecotone.tech/pricing, or remove #[Collected].");

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: [],
        );
    }
}
