<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Aws\Sqs\SqsClient as AwsSqsClient;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ValueError;

/**
 * licence Apache-2.0
 * @internal
 */
final class ReceiveFailureTest extends TestCase
{
    public function test_failure_of_the_sqs_client_caused_by_an_error_reaches_the_consumer_as_it_was_thrown(): void
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
            }
        };
        $failingClient = new AwsSqsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => static fn () => throw new RuntimeException('The request could not be sent', 0, new ValueError('Argument #2 ($option) is not a valid cURL option')),
        ]);
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$orders::class],
            containerOrAvailableServices: [$orders, SqsConnectionFactory::class => new SqsConnectionFactory($failingClient)],
            extensionObjects: [SqsBackedMessageChannelBuilder::create('orders')],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The request could not be sent');

        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    }
}
