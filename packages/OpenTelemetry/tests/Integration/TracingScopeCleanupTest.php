<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use const E_USER_NOTICE;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Channel\ExceptionalQueueChannel;
use Ecotone\Messaging\Channel\SimpleChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Api\Distribution\DistributedServiceMap;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\EventBus;
use Ecotone\Test\LicenceTesting;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class TracingScopeCleanupTest extends TracingTestCase
{
    public function test_no_scope_detach_notices_when_sending_to_asynchronous_channel_fails(): void
    {
        $ecotoneLite = $this->bootstrapWithEventForwardedToSecondChannel(
            channelsToRegister: [
                SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ExceptionalQueueChannel::createWithExceptionOnSend('failing_channel'),
            ],
        );

        $ecotoneLite->sendCommandWithRoutingKey('user.register', '1');

        [$caughtException, $scopeNotices] = $this->invokeCapturingScopeNotices(
            fn () => $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup())
        );

        $this->assertNotNull($caughtException);
        $this->assertStringContainsString('Exception on send', $caughtException->getMessage());
        $this->assertSame([], $scopeNotices);
    }

    public function test_no_scope_detach_notices_when_channel_interceptor_fails_before_send(): void
    {
        $throwingChannelInterceptor = new class () extends AbstractChannelInterceptor {
            public function preSend(Message $message, MessageChannel $messageChannel): ?Message
            {
                throw new RuntimeException('Exception before send');
            }
        };

        $ecotoneLite = $this->bootstrapWithEventForwardedToSecondChannel(
            channelsToRegister: [
                SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                SimpleMessageChannelBuilder::createQueueChannel('failing_channel'),
                SimpleChannelInterceptorBuilder::create('failing_channel', 'throwingChannelInterceptor'),
            ],
            servicesToRegister: ['throwingChannelInterceptor' => $throwingChannelInterceptor],
        );

        $ecotoneLite->sendCommandWithRoutingKey('user.register', '1');

        [$caughtException, $scopeNotices] = $this->invokeCapturingScopeNotices(
            fn () => $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup())
        );

        $this->assertNotNull($caughtException);
        $this->assertStringContainsString('Exception before send', $caughtException->getMessage());
        $this->assertSame([], $scopeNotices);
    }

    public function test_no_scope_detach_notices_when_message_is_filtered_out_after_tracing_started(): void
    {
        $filteringChannelInterceptor = new class () extends AbstractChannelInterceptor {
            public function preSend(Message $message, MessageChannel $messageChannel): ?Message
            {
                return null;
            }
        };

        $ecotoneLite = $this->bootstrapWithEventForwardedToSecondChannel(
            channelsToRegister: [
                SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                SimpleMessageChannelBuilder::createQueueChannel('failing_channel'),
                SimpleChannelInterceptorBuilder::create('failing_channel', 'filteringChannelInterceptor'),
            ],
            servicesToRegister: ['filteringChannelInterceptor' => $filteringChannelInterceptor],
        );

        $ecotoneLite->sendCommandWithRoutingKey('user.register', '1');

        [$caughtException, $scopeNotices] = $this->invokeCapturingScopeNotices(
            fn () => $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup())
        );

        $this->assertNull($caughtException);
        $this->assertSame([], $scopeNotices);
    }

    public function test_no_scope_detach_notices_when_cleanup_of_another_interceptor_fails_after_send_failure(): void
    {
        $throwingCleanupInterceptor = new class () extends AbstractChannelInterceptor {
            public function afterSendCompletion(Message $message, MessageChannel $messageChannel, ?Throwable $exception): bool
            {
                if ($exception !== null) {
                    throw new RuntimeException('Exception during cleanup');
                }

                return false;
            }
        };

        $ecotoneLite = $this->bootstrapWithEventForwardedToSecondChannel(
            channelsToRegister: [
                SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ExceptionalQueueChannel::createWithExceptionOnSend('failing_channel'),
                SimpleChannelInterceptorBuilder::create('failing_channel', 'throwingCleanupInterceptor')->withPrecedence(500),
            ],
            servicesToRegister: ['throwingCleanupInterceptor' => $throwingCleanupInterceptor],
        );

        $ecotoneLite->sendCommandWithRoutingKey('user.register', '1');

        [$caughtException, $scopeNotices] = $this->invokeCapturingScopeNotices(
            fn () => $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup())
        );

        $this->assertNotNull($caughtException);
        $this->assertSame([], $scopeNotices);
    }

    public function test_no_scope_detach_notices_when_post_send_of_another_interceptor_fails(): void
    {
        $throwingPostSendInterceptor = new class () extends AbstractChannelInterceptor {
            public function postSend(Message $message, MessageChannel $messageChannel): void
            {
                throw new RuntimeException('Exception after send');
            }
        };

        $ecotoneLite = $this->bootstrapWithEventForwardedToSecondChannel(
            channelsToRegister: [
                SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                SimpleMessageChannelBuilder::createQueueChannel('failing_channel'),
                SimpleChannelInterceptorBuilder::create('failing_channel', 'throwingPostSendInterceptor')->withPrecedence(500),
            ],
            servicesToRegister: ['throwingPostSendInterceptor' => $throwingPostSendInterceptor],
        );

        $ecotoneLite->sendCommandWithRoutingKey('user.register', '1');

        [$caughtException, $scopeNotices] = $this->invokeCapturingScopeNotices(
            fn () => $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup())
        );

        $this->assertNotNull($caughtException);
        $this->assertStringContainsString('Exception after send', $caughtException->getMessage());
        $this->assertSame([], $scopeNotices);
    }

    public function test_no_scope_detach_notices_when_interceptor_replaces_message_dropping_framework_headers(): void
    {
        $rebuildingChannelInterceptor = new class () extends AbstractChannelInterceptor {
            public function preSend(Message $message, MessageChannel $messageChannel): ?Message
            {
                return MessageBuilder::withPayload($message->getPayload())->build();
            }
        };

        $ecotoneLite = $this->bootstrapWithEventForwardedToSecondChannel(
            channelsToRegister: [
                SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                SimpleMessageChannelBuilder::createQueueChannel('failing_channel'),
                SimpleChannelInterceptorBuilder::create('failing_channel', 'rebuildingChannelInterceptor'),
            ],
            servicesToRegister: ['rebuildingChannelInterceptor' => $rebuildingChannelInterceptor],
        );

        $ecotoneLite->sendCommandWithRoutingKey('user.register', '1');

        [$caughtException, $scopeNotices] = $this->invokeCapturingScopeNotices(
            fn () => $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup())
        );

        $this->assertNull($caughtException);
        $this->assertSame([], $scopeNotices);
    }

    public function test_no_scope_detach_notices_when_error_channel_send_fails(): void
    {
        $userService = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('user.register', endpointId: 'userRegisterEndpoint')]
            public function register(string $userId): void
            {
                throw new RuntimeException('Handler failure');
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$userService::class],
            [$userService, TracerProviderInterface::class => TracingTestCase::prepareTracer(new InMemoryExporter())],
            ServiceConfiguration::createWithDefaults()
                ->withDefaultErrorChannel('error_channel')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                    ExceptionalQueueChannel::createWithExceptionOnSend('error_channel'),
                ])
        );

        $ecotoneLite->sendCommandWithRoutingKey('user.register', '1');

        [, $scopeNotices] = $this->invokeCapturingScopeNotices(
            fn () => $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        );

        $this->assertSame([], $scopeNotices);
    }

    public function test_no_scope_detach_notices_when_distributed_bus_send_fails(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer(new InMemoryExporter())],
            ServiceConfiguration::createWithDefaults()
                ->withServiceName('user_service')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    ExceptionalQueueChannel::createWithExceptionOnSend('distributed_channel'),
                    DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'distributed_channel'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        [$caughtException, $scopeNotices] = $this->invokeCapturingScopeNotices(
            fn () => $ecotoneLite->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed billing address')
        );

        $this->assertNotNull($caughtException);
        $this->assertStringContainsString('Exception on send', $caughtException->getMessage());
        $this->assertSame([], $scopeNotices);
    }

    private function bootstrapWithEventForwardedToSecondChannel(array $channelsToRegister, array $servicesToRegister = []): FlowTestSupport
    {
        $userService = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('user.register', endpointId: 'userRegisterEndpoint')]
            public function register(string $userId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('user.registered', $userId);
            }
        };
        $userNotifier = new class () {
            #[Asynchronous('failing_channel')]
            #[EventHandler('user.registered', endpointId: 'userNotifierEndpoint')]
            public function notify(string $userId): void
            {
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$userService::class, $userNotifier::class],
            array_merge(
                [$userService, $userNotifier, TracerProviderInterface::class => TracingTestCase::prepareTracer(new InMemoryExporter())],
                $servicesToRegister,
            ),
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects($channelsToRegister)
        );
    }

    /**
     * @return array{0: ?Throwable, 1: string[]}
     */
    private function invokeCapturingScopeNotices(callable $action): array
    {
        $scopeNotices = [];
        set_error_handler(static function (int $errorNumber, string $errorMessage) use (&$scopeNotices): bool {
            $scopeNotices[] = $errorMessage;

            return true;
        }, E_USER_NOTICE);

        $caughtException = null;
        try {
            $action();
        } catch (Throwable $exception) {
            $caughtException = $exception;
        } finally {
            restore_error_handler();
        }

        return [$caughtException, $scopeNotices];
    }
}
