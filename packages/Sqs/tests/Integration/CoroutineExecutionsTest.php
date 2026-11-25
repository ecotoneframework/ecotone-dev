<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Aws\Sqs\SqsClient as AwsSqsClient;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\CoroutineTesting;
use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;
use Swoole\Coroutine;
use Test\Ecotone\Sqs\ConnectionTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineExecutionsTest extends ConnectionTestCase
{
    private const CLIENT_OF_THE_APPLICATION_REFUSED = 'SqsConnectionFactory was given an Aws\Sqs\SqsClient the application built itself, and is used inside a Swoole or OpenSwoole coroutine. '
        . 'That client sends every request through one HTTP handler for the whole process, and two coroutines calling it at once stop the process. '
        . "Pass the connection settings instead, `new SqsConnectionFactory('sqs:?key=...&secret=...&region=...')` or the same settings as an array, so every coroutine builds a client of its own, "
        . 'or handle one message at a time: set `enable_coroutine` to false on the server and run consumers without the `coroutines` option.';

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_coroutines_publishing_and_consuming_at_once_handle_every_message_once_with_its_own_headers(): void
    {
        $ecotone = $this->bootstrapOrders();

        $this->runAtOnceWithoutReportingDeprecationsOfTheSqsClient(
            ...$this->publishersOfOrders($ecotone, publishers: 4, ordersOfEachPublisher: 25),
            ...$this->consumersOf($ecotone, 'coroutine_orders', consumers: 2),
            ...$this->consumersOf($ecotone, 'coroutine_shipments', consumers: 2),
        );
        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->run('coroutine_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertEquals($this->ordersWithTheirTenants(publishers: 4, ordersOfEachPublisher: 25), $ecotone->sendQueryWithRouting('order.shipped'));
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_consumers_on_coroutines_handle_every_message_once_with_its_own_headers(): void
    {
        $ecotone = $this->bootstrapOrders();
        $this->runAtOnceWithoutReportingDeprecationsOfTheSqsClient(...$this->publishersOfOrders($ecotone, publishers: 4, ordersOfEachPublisher: 25));

        $this->withoutReportingDeprecationsOfTheSqsClient(fn () => $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3)));
        $this->withoutReportingDeprecationsOfTheSqsClient(fn () => $ecotone->run('coroutine_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3)));

        $this->assertEquals($this->ordersWithTheirTenants(publishers: 4, ordersOfEachPublisher: 25), $ecotone->sendQueryWithRouting('order.shipped'));
    }

    #[RequiresPhpExtension('openswoole')]
    #[Group('openswoole')]
    public function test_sqs_is_refused_in_an_openswoole_coroutine_whose_curl_hook_rejects_the_aws_sdk(): void
    {
        $ecotone = $this->bootstrapOrders();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'An SQS queue is used inside an OpenSwoole coroutine with the curl hook enabled. '
            . 'OpenSwoole\'s curl hook rejects options the HTTP client of the AWS SDK sets, so every SQS request fails there with `curl_setopt(): Argument #2 ($option) is not a valid cURL option`. '
            . 'Run the application on Swoole, where coroutines wait on SQS requests; '
            . 'or switch the hook off before the coroutines start, `OpenSwoole\Runtime::enableCoroutine(OpenSwoole\Runtime::HOOK_ALL & ~(OpenSwoole\Runtime::HOOK_CURL | OpenSwoole\Runtime::HOOK_NATIVE_CURL))` or the same value as `hook_flags` of the server, and every SQS request then stops the other coroutines of the process while it waits; '
            . 'or handle one message at a time: set `enable_coroutine` to false on the server and run consumers without the `coroutines` option.'
        );

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'order-1'], metadata: ['tenant' => 'tenant-1']));
    }

    #[RequiresPhpExtension('openswoole')]
    #[Group('openswoole')]
    public function test_openswoole_coroutines_with_the_curl_hook_switched_off_handle_every_message_once_with_its_own_headers(): void
    {
        $ecotone = $this->bootstrapOrders();

        $this->withoutReportingDeprecationsOfTheSqsClient(fn () => CoroutineTesting::runAtOnceWithHooks(
            SWOOLE_HOOK_ALL & ~(SWOOLE_HOOK_CURL | SWOOLE_HOOK_NATIVE_CURL),
            ...$this->publishersOfOrders($ecotone, publishers: 4, ordersOfEachPublisher: 5),
            ...$this->consumersOf($ecotone, 'coroutine_orders', consumers: 2),
            ...$this->consumersOf($ecotone, 'coroutine_shipments', consumers: 2),
        ));
        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->run('coroutine_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertEquals($this->ordersWithTheirTenants(publishers: 4, ordersOfEachPublisher: 5), $ecotone->sendQueryWithRouting('order.shipped'));
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_consumer_coroutine_whose_handler_fails_sends_only_its_own_message_to_the_error_channel(): void
    {
        $orders = new class () {
            private array $placed = [];
            private int $failed = 0;

            #[Asynchronous('coroutine_orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
                usleep(20_000);
                if ($orderId === 'refused') {
                    throw new RuntimeException('Order was refused');
                }
                $this->placed[] = $orderId;
            }

            #[InternalHandler('failed_orders')]
            public function whenFailed(Message $failure): void
            {
                $this->failed++;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                sort($this->placed);

                return $this->placed;
            }

            #[QueryHandler('order.failed')]
            public function failed(): int
            {
                return $this->failed;
            }
        };
        $ecotone = $this->bootstrap(
            handlers: $orders,
            connectionFactory: self::getConnection(),
            extensionObjects: [ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('failed_orders'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
        );
        foreach (['order-1', 'order-2', 'refused', 'order-3', 'order-4', 'order-5'] as $orderId) {
            $ecotone->sendCommandWithRouting('order.place', $orderId);
        }

        $this->withoutReportingDeprecationsOfTheSqsClient(fn () => $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withCoroutines(3)));

        $this->assertSame(['order-1', 'order-2', 'order-3', 'order-4', 'order-5'], $ecotone->sendQueryWithRouting('order.placed'));
        $this->assertSame(1, $ecotone->sendQueryWithRouting('order.failed'));
    }

    public function test_publishing_inside_a_coroutine_with_a_client_the_application_built_itself_is_refused(): void
    {
        $ecotone = $this->bootstrap(handlers: $this->orders(), connectionFactory: new SqsConnectionFactory($this->clientTheApplicationBuiltItself()));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::CLIENT_OF_THE_APPLICATION_REFUSED);

        CoroutineTesting::runAtOnceWithHooks(0, static fn () => $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'order-1'], metadata: ['tenant' => 'tenant-1']));
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_consumer_on_coroutines_with_a_client_the_application_built_itself_is_refused(): void
    {
        $ecotone = $this->bootstrap(handlers: $this->orders(), connectionFactory: new SqsConnectionFactory($this->clientTheApplicationBuiltItself()));
        $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'order-1'], metadata: ['tenant' => 'tenant-1']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::CLIENT_OF_THE_APPLICATION_REFUSED);

        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
    }

    private function clientTheApplicationBuiltItself(): AwsSqsClient
    {
        parse_str(substr(getenv('SQS_DSN') ?: 'sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localhost:4566&version=latest', 5), $settings);

        return new AwsSqsClient(['region' => $settings['region'], 'version' => $settings['version'], 'endpoint' => $settings['endpoint'], 'credentials' => ['key' => $settings['key'], 'secret' => $settings['secret']]]);
    }

    private function runAtOnceWithoutReportingDeprecationsOfTheSqsClient(callable ...$coroutines): void
    {
        $this->withoutReportingDeprecationsOfTheSqsClient(static fn () => CoroutineTesting::runAtOnce(...$coroutines));
    }

    private function withoutReportingDeprecationsOfTheSqsClient(callable $work): void
    {
        set_error_handler(static fn (): bool => true, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            $work();
        } finally {
            restore_error_handler();
        }
    }

    private function publishersOfOrders(FlowTestSupport $ecotone, int $publishers, int $ordersOfEachPublisher): array
    {
        $coroutines = [];
        for ($publisher = 1; $publisher <= $publishers; $publisher++) {
            $coroutines[] = static function () use ($ecotone, $publisher, $ordersOfEachPublisher): void {
                for ($order = 1; $order <= $ordersOfEachPublisher; $order++) {
                    $ecotone->sendCommandWithRouting('order.register', ['orderId' => "order-{$publisher}-{$order}"], metadata: ['tenant' => "tenant-{$publisher}-{$order}"]);
                }
            };
        }

        return $coroutines;
    }

    private function consumersOf(FlowTestSupport $ecotone, string $channelName, int $consumers): array
    {
        return array_fill(0, $consumers, static fn () => $ecotone->run($channelName, ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(1500)));
    }

    private function ordersWithTheirTenants(int $publishers, int $ordersOfEachPublisher): array
    {
        $orders = [];
        for ($publisher = 1; $publisher <= $publishers; $publisher++) {
            for ($order = 1; $order <= $ordersOfEachPublisher; $order++) {
                $orders["order-{$publisher}-{$order}"] = ["tenant-{$publisher}-{$order}"];
            }
        }

        return $orders;
    }

    private function bootstrapOrders(): FlowTestSupport
    {
        return $this->bootstrap(handlers: $this->orders(), connectionFactory: self::getConnection());
    }

    private function orders(): object
    {
        return new class () {
            private array $shipped = [];

            #[CommandHandler('order.register')]
            public function register(array $command, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('order.place', $command['orderId']);
                usleep(2_000);
            }

            #[Asynchronous('coroutine_orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId, CommandBus $commandBus): void
            {
                usleep(1_000);
                $commandBus->sendWithRouting('order.ship', $orderId);
            }

            #[Asynchronous('coroutine_shipments')]
            #[CommandHandler('order.ship', endpointId: 'order.ship.endpoint')]
            public function ship(string $orderId, #[Header('tenant')] string $tenant): void
            {
                $this->shipped[$orderId][] = $tenant;
            }

            #[QueryHandler('order.shipped')]
            public function shipped(): array
            {
                return $this->shipped;
            }
        };
    }

    private function bootstrap(object $handlers, SqsConnectionFactory $connectionFactory, array $extensionObjects = []): FlowTestSupport
    {
        $context = self::getConnection()->createContext();
        foreach (['coroutine_orders', 'coroutine_shipments'] as $queueName) {
            try {
                $context->deleteQueue($context->createQueue($queueName));
            } catch (Exception) {
            }
        }

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handlers::class],
            containerOrAvailableServices: [$handlers, SqsConnectionFactory::class => $connectionFactory],
            extensionObjects: [
                SqsBackedMessageChannelBuilder::create('coroutine_orders'),
                SqsBackedMessageChannelBuilder::create('coroutine_shipments'),
                ...$extensionObjects,
            ],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
        );
    }
}
