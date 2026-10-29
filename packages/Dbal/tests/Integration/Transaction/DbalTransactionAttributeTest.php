<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalTransaction\DbalTransaction;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\CommandHandler;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalTransactionAttributeTest extends DbalMessagingTestCase
{
    public function test_handler_with_dbal_transaction_attribute_commits_on_success(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->sendCommandWithRoutingKey('person.register', 1);

        self::assertSame(1, $this->registeredPersons());
    }

    public function test_handler_with_dbal_transaction_attribute_rolls_back_on_failure(): void
    {
        $ecotone = $this->bootstrap();

        try {
            $ecotone->sendCommandWithRoutingKey('person.registerAndFail', 1);
            self::fail('Expected handler to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Failure after write.', $exception->getMessage());
        }

        self::assertSame(0, $this->registeredPersons());
    }

    private function bootstrap(): FlowTestSupport
    {
        $this->setupUserTable();

        $personService = new class () {
            #[DbalTransaction]
            #[CommandHandler('person.register')]
            public function register(int $personId, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $connectionFactory->createContext()->getDbalConnection()->insert('persons', ['person_id' => $personId, 'name' => 'John']);
            }

            #[DbalTransaction]
            #[CommandHandler('person.registerAndFail')]
            public function registerAndFail(int $personId, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $connectionFactory->createContext()->getDbalConnection()->insert('persons', ['person_id' => $personId, 'name' => 'John']);

                throw new RuntimeException('Failure after write.');
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$personService::class],
            [$personService, DbalConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false),
                ]),
            addInMemoryStateStoredRepository: false,
        );
    }

    private function registeredPersons(): int
    {
        return (int) $this->getConnection()->fetchOne('SELECT COUNT(*) FROM persons');
    }
}
