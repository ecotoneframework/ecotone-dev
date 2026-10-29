<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DelayedRetry;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class DistributedIdempotencyKeyTest extends DbalMessagingTestCase
{
    public static function deduplicationStores(): iterable
    {
        yield 'in-memory' => [true];
        yield 'dbal' => [false];
    }

    #[DataProvider('deduplicationStores')]
    public function test_a_distributed_command_sent_twice_with_the_same_idempotency_key_is_handled_once(bool $inMemory): void
    {
        [$userService, $ticketService, $tickets] = $this->services($inMemory);

        $userService->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'Printer is broken', idempotencyKey: 'request-1');
        $userService->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'Printer is broken', idempotencyKey: 'request-1');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['command: Printer is broken'], $tickets->handled);
    }

    #[DataProvider('deduplicationStores')]
    public function test_a_converted_distributed_command_sent_twice_with_the_same_idempotency_key_is_handled_once(bool $inMemory): void
    {
        [$userService, $ticketService, $tickets] = $this->services($inMemory);

        $userService->getDistributedBus()->convertAndSendCommand('ticket_service', 'ticket.create', ['description' => 'Printer is broken'], idempotencyKey: 'request-1');
        $userService->getDistributedBus()->convertAndSendCommand('ticket_service', 'ticket.create', ['description' => 'Printer is broken'], idempotencyKey: 'request-1');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertCount(1, $tickets->handled);
    }

    #[DataProvider('deduplicationStores')]
    public function test_a_distributed_event_published_twice_with_the_same_idempotency_key_is_handled_once(bool $inMemory): void
    {
        [$userService, $ticketService, $tickets] = $this->services($inMemory);

        $userService->getDistributedBus()->publishEvent('user.registered', 'johny', idempotencyKey: 'registration-1');
        $userService->getDistributedBus()->publishEvent('user.registered', 'johny', idempotencyKey: 'registration-1');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['event: johny'], $tickets->handled);
    }

    #[DataProvider('deduplicationStores')]
    public function test_a_converted_distributed_event_published_twice_with_the_same_idempotency_key_is_handled_once(bool $inMemory): void
    {
        [$userService, $ticketService, $tickets] = $this->services($inMemory);

        $userService->getDistributedBus()->convertAndPublishEvent('user.registered', ['name' => 'johny'], idempotencyKey: 'registration-1');
        $userService->getDistributedBus()->convertAndPublishEvent('user.registered', ['name' => 'johny'], idempotencyKey: 'registration-1');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertCount(1, $tickets->handled);
    }

    #[DataProvider('deduplicationStores')]
    public function test_a_distributed_message_sent_twice_with_the_same_idempotency_key_is_handled_once(bool $inMemory): void
    {
        [$userService, $ticketService, $tickets] = $this->services($inMemory);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.confirm', 'seat-7', idempotencyKey: 'confirm-1');
        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.confirm', 'seat-7', idempotencyKey: 'confirm-1');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['message: seat-7'], $tickets->handled);
    }

    #[DataProvider('deduplicationStores')]
    public function test_distributed_messages_with_different_idempotency_keys_are_each_handled(bool $inMemory): void
    {
        [$userService, $ticketService, $tickets] = $this->services($inMemory);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.confirm', 'seat-7', idempotencyKey: 'confirm-1');
        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.confirm', 'seat-7', idempotencyKey: 'confirm-2');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['message: seat-7', 'message: seat-7'], $tickets->handled);
    }

    #[DataProvider('deduplicationStores')]
    public function test_distributed_messages_sent_without_an_idempotency_key_are_each_handled(bool $inMemory): void
    {
        [$userService, $ticketService, $tickets] = $this->services($inMemory);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.confirm', 'seat-7');
        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.confirm', 'seat-7');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['message: seat-7', 'message: seat-7'], $tickets->handled);
    }

    #[DataProvider('deduplicationStores')]
    public function test_a_send_repeated_by_a_retried_asynchronous_handler_is_handled_once_by_the_target(bool $inMemory): void
    {
        [$userService, $ticketService, $tickets] = $this->services($inMemory);

        $userService->sendCommandWithRouting('order.confirm', 'seat-7');
        $userService->run('user_outbox', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(2, $userService->sendQueryWithRouting('order.confirmationAttempts'));
        self::assertSame(['message: seat-7'], $tickets->handled);
    }

    #[DataProvider('deduplicationStores')]
    public function test_an_idempotency_key_does_not_deduplicate_the_messages_the_target_sends_onwards(bool $inMemory): void
    {
        [$userService, $ticketService, $tickets] = $this->services($inMemory);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.issue', 'seat-7', idempotencyKey: 'issue-1');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $ticketService->run('ticket_work', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['printed: seat-7 front', 'printed: seat-7 back'], $tickets->handled);
    }

    /**
     * @return array{FlowTestSupport, FlowTestSupport, object}
     */
    private function services(bool $inMemoryDeduplication): array
    {
        $channels = [
            SimpleMessageChannelBuilder::createQueueChannel('ticket_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('ticket_work'),
            SimpleMessageChannelBuilder::createQueueChannel('user_outbox', delayable: false),
        ];
        $tickets = new class () {
            public array $handled = [];

            #[Distributed]
            #[CommandHandler('ticket.create')]
            public function create(mixed $command): void
            {
                $this->handled[] = 'command: ' . (is_array($command) ? $command['description'] : $command);
            }

            #[Distributed]
            #[EventHandler('user.registered')]
            public function whenRegistered(mixed $event): void
            {
                $this->handled[] = 'event: ' . (is_array($event) ? $event['name'] : $event);
            }

            #[Distributed]
            #[InternalHandler('ticket.confirm')]
            public function confirm(string $seat): void
            {
                $this->handled[] = 'message: ' . $seat;
            }

            #[Distributed]
            #[InternalHandler('ticket.issue')]
            public function issue(string $seat, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('ticket.print', $seat . ' front');
                $commandBus->sendWithRouting('ticket.print', $seat . ' back');
            }

            #[Asynchronous('ticket_work')]
            #[CommandHandler('ticket.print', endpointId: 'ticket.print.endpoint')]
            public function print(string $side): void
            {
                $this->handled[] = 'printed: ' . $side;
            }
        };
        $orders = new class () {
            private int $attempts = 0;

            #[Asynchronous('user_outbox', asynchronousExecution: [new DelayedRetry(initialDelayInMilliseconds: 0, maxRetries: 1)])]
            #[CommandHandler('order.confirm', endpointId: 'order.confirm.endpoint')]
            public function confirm(string $seat, DistributedBus $distributedBus): void
            {
                $this->attempts++;
                $distributedBus->sendMessage('ticket_service', 'ticket.confirm', $seat, idempotencyKey: 'confirm-' . $seat);
            }

            #[QueryHandler('order.confirmationAttempts')]
            public function confirmationAttempts(): int
            {
                return $this->attempts;
            }

            #[Around(pointcut: CommandHandler::class)]
            public function failTheFirstAttemptAfterItSent(MethodInvocation $methodInvocation): mixed
            {
                $result = $methodInvocation->proceed();
                if ($this->attempts === 1) {
                    throw new RuntimeException('The callback failed after it had sent');
                }

                return $result;
            }
        };

        $userService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$orders::class],
            containerOrAvailableServices: [$orders],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()
                    ->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel')
                    ->withEventMapping(channelName: 'ticket_channel', subscriptionKeys: ['user.*']),
                ServiceConfiguration::createWithDefaults()->withServiceName('user_service'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ticketService = $this->bootstrapFlowTesting(
            classesToResolve: [$tickets::class],
            containerOrAvailableServices: [$tickets, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize(),
                ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'),
                DbalConfiguration::createWithDefaults()->withDeduplication(inMemory: $inMemoryDeduplication),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return [$userService, $ticketService, $tickets];
    }
}
