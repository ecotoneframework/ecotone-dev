<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CollectingConformanceTest extends TestCase
{
    public static function implementations(): iterable
    {
        yield 'in_memory' => [false];
        yield 'database' => [true];
    }

    #[DataProvider('implementations')]
    public function test_collected_handler_runs_once_when_the_batch_reaches_max_size(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(mixed $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);

        $ecotone->sendCommandWithRouting('collect', 'second');
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertSame(['first', 'second'], $handler->batches[0]->payloads);
        self::assertSame(2, $handler->batches[0]->size);
        self::assertNull($handler->batches[0]->groupKey);
    }
}
