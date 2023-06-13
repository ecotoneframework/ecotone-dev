<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\PollableChannel;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction\OrderRegisteringGateway;
use Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction\OrderService;

/**
 * @internal
 */
final class DbalTransactionInterceptorTest extends DbalMessagingTestCase
{
    public function test_turning_off_transactions_for_polling_consumer()
    {
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class, OrderRegisteringGateway::class],
            [OrderService::failAtFirstCall(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withoutTransactionOnAsynchronousEndpoints(['orders']),
                    DbalBackedMessageChannelBuilder::create('orders'),
                    DbalBackedMessageChannelBuilder::create('processOrders'),
                    PollingMetadata::create('orders')
                        ->withTestingSetup(failAtError: false),
                    PollingMetadata::create('processOrders')->withTestingSetup(),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withEnvironment('test'),
        );

        $orderId = 'someId';
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', $orderId);
        $ecotoneLite->run('orders');

        /** As exception is thrown after sending to processOrders, this should be not null because transactions are off */

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannelByName('processOrders');
        $this->assertNotNull($messageChannel->receiveWithTimeout(1));
    }
}
