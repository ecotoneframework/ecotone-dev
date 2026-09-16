<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\MultiTenantConfiguration;
use Ecotone\Api\Dbal\OutboxForwardingMessageChannel;
use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Interop\Queue\ConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class BatchForwardingMultiTenantTest extends DbalMessagingTestCase
{
    public function test_each_run_publishes_batch_from_next_tenant_outbox_in_round_robin(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [
                $orderService,
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    MultiTenantConfiguration::create(
                        'tenant',
                        ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection'],
                    ),
                    OutboxForwardingMessageChannel::create('orders', 'outbox', 'orderProcessing'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    SimpleMessageChannelBuilder::createQueueChannel('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRouting('order.register', 'espresso', metadata: ['tenant' => 'tenant_a']);
        $messaging->sendCommandWithRouting('order.register', 'latte', metadata: ['tenant' => 'tenant_a']);
        $messaging->sendCommandWithRouting('order.register', 'flat white', metadata: ['tenant' => 'tenant_b']);

        $this->assertSame(2, $this->amountOfOutboxRowsFor($this->connectionForTenantA()));
        $this->assertSame(1, $this->amountOfOutboxRowsFor($this->connectionForTenantB()));

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, executionTimeLimitInMilliseconds: 5000));

        $this->assertSame(['espresso', 'latte'], $this->payloadsOf($this->receiveAllFrom($messaging->getMessageChannel('orderProcessing'))));
        $this->assertSame(0, $this->amountOfOutboxRowsFor($this->connectionForTenantA()));
        $this->assertSame(1, $this->amountOfOutboxRowsFor($this->connectionForTenantB()));

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, executionTimeLimitInMilliseconds: 5000));

        $this->assertSame(['flat white'], $this->payloadsOf($this->receiveAllFrom($messaging->getMessageChannel('orderProcessing'))));
        $this->assertSame(0, $this->amountOfOutboxRowsFor($this->connectionForTenantB()));
    }

    private function amountOfOutboxRowsFor(ConnectionFactory $connectionFactory): int
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();
        if (! self::checkIfTableExists($connection, 'enqueue')) {
            return 0;
        }

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM enqueue WHERE queue = ?', ['outbox']);
    }

    /**
     * @param \Ecotone\Messaging\Message[] $messages
     * @return string[]
     */
    private function payloadsOf(array $messages): array
    {
        return array_map(fn ($message) => $message->getPayload(), $messages);
    }

    private function receiveAllFrom(\Ecotone\Messaging\PollableChannel $channel): array
    {
        $messages = [];
        while ($message = $channel->receive()) {
            $messages[] = $message;
        }

        return $messages;
    }
}
