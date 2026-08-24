<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Recoverability;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\MultiTenantConfiguration;
use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Dbal\Recoverability\DbalDeadLetterHandler;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Interop\Queue\ConnectionFactory;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MultiTenantDeadLetterTest extends DbalMessagingTestCase
{
    public function test_failed_message_from_polling_consumer_is_stored_in_dead_letter_of_related_tenant(): void
    {
        $orderService = new class () {
            #[Asynchronous('async')]
            #[CommandHandler('order.place', endpointId: 'placeOrderEndpoint')]
            public function placeOrder(string $order): void
            {
                throw new RuntimeException('Order processing has failed');
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [
                $orderService,
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withDefaultErrorChannel('dbal_dead_letter')
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create('async'),
                    MultiTenantConfiguration::create(
                        'tenant',
                        ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection'],
                    ),
                ])
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE]),
        );

        $ecotoneLite->sendCommandWithRoutingKey('order.place', 'milk', metadata: ['tenant' => 'tenant_a']);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2, maxExecutionTimeInMilliseconds: 1000, failAtError: false));

        $this->assertSame(1, $this->amountOfDeadLetterMessagesIn($this->connectionForTenantA()));
        $this->assertSame(0, $this->amountOfDeadLetterMessagesIn($this->connectionForTenantB()));
    }

    private function amountOfDeadLetterMessagesIn(ConnectionFactory $connectionFactory): int
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();

        if (! self::checkIfTableExists($connection, DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE)) {
            return 0;
        }

        return (int) $connection
            ->executeQuery(sprintf('SELECT COUNT(*) FROM %s', DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE))
            ->fetchOne();
    }
}
