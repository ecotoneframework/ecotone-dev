<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Closure;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Dbal\Recoverability\DbalDeadLetterStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MissingTableRefusalTest extends DbalMessagingTestCase
{
    public static function deadLetterOperations(): iterable
    {
        yield 'store' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->store(MessageBuilder::withPayload('failed')->build())];
        yield 'list' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->list(10, 0)];
        yield 'count' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->count()];
        yield 'show' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->show('m-1')];
        yield 'replay' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->replay('m-1')];
        yield 'replay all' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->replayAll()];
        yield 'delete' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->delete('m-1')];
        yield 'delete all' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->deleteAll()];
    }

    #[DataProvider('deadLetterOperations')]
    public function test_every_dead_letter_operation_names_the_setup_when_its_table_is_missing(Closure $operation): void
    {
        $ecotone = $this->bootstrapWithoutTables(DbalConfiguration::createWithDefaults());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(DeadLetterTableManager::FEATURE_NAME, DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE, null));

        $operation($ecotone->getGateway(DeadLetterGateway::class));
    }

    private function bootstrapWithoutTables(DbalConfiguration $dbalConfiguration): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([$dbalConfiguration]),
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
        );
    }
}
