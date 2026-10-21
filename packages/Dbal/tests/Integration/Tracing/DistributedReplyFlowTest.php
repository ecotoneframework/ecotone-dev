<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Test\LicenceTesting;
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
final class DistributedReplyFlowTest extends DbalMessagingTestCase
{
    private const CHANNELS = 'shared_channels';

    public function test_a_flow_that_sent_a_distributed_request_is_completed_once_its_reply_is_processed_by_an_untracked_service(): void
    {
        $tracingId = bin2hex(random_bytes(16));
        $users = $this->userHandlers();
        $userService = $this->service('user_service', $users, 'ticket_channel', 'user_channel', FlowTrackingConfiguration::createWithDefaults(), $this->getConnectionFactory());
        $ticketService = $this->service('ticket_service', $this->ticketHandlers(), 'user_channel', 'ticket_channel', null, $this->getConnectionFactory());

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', metadata: [MessageHeaders::TRACING_ID => $tracingId], replyRoutingKey: 'ticket.created');
        $gateway = $userService->getGateway(TracingGateway::class);
        self::assertFalse($gateway->isCompletedFor($tracingId, $users::class));
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertFalse($gateway->isCompletedFor($tracingId, $users::class));
        $this->consumeThroughConsole($userService, 'user_channel');

        self::assertTrue($gateway->isCompletedFor($tracingId, $users::class));
    }

    public function test_a_flow_that_sent_a_distributed_request_is_completed_once_its_reply_is_processed_by_a_service_tracking_in_its_own_database(): void
    {
        $tracingId = bin2hex(random_bytes(16));
        $users = $this->userHandlers();
        $userService = $this->service('user_service', $users, 'ticket_channel', 'user_channel', FlowTrackingConfiguration::createWithDefaults(), $this->getConnectionFactory());
        $ticketService = $this->service('ticket_service', $this->ticketHandlers(), 'user_channel', 'ticket_channel', FlowTrackingConfiguration::createWithDefaults(), $this->connectionForTenantB());

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', metadata: [MessageHeaders::TRACING_ID => $tracingId], replyRoutingKey: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->consumeThroughConsole($userService, 'user_channel');

        $gateway = $userService->getGateway(TracingGateway::class);
        self::assertTrue($gateway->isCompletedFor($tracingId, $users::class));
    }

    public function test_an_unrelated_asynchronous_branch_still_pending_keeps_the_flow_not_completed(): void
    {
        $tracingId = bin2hex(random_bytes(16));
        $users = $this->userHandlers();
        $other = new class () {
            #[Asynchronous('other_branch')]
            #[EventHandler('branch.started', 'other.branch.endpoint')]
            public function started(string $payload): void
            {
            }
        };
        $userService = $this->service('user_service', $users, 'ticket_channel', 'user_channel', FlowTrackingConfiguration::createWithDefaults(), $this->getConnectionFactory(), [$other], [DbalBackedMessageChannelBuilder::create('other_branch', self::CHANNELS)]);
        $ticketService = $this->service('ticket_service', $this->ticketHandlers(), 'user_channel', 'ticket_channel', null, $this->getConnectionFactory());

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', metadata: [MessageHeaders::TRACING_ID => $tracingId], replyRoutingKey: 'ticket.created');
        $userService->publishEventWithRouting('branch.started', 'x', metadata: [MessageHeaders::TRACING_ID => $tracingId]);
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->consumeThroughConsole($userService, 'user_channel');

        $gateway = $userService->getGateway(TracingGateway::class);
        self::assertFalse($gateway->isCompletedFor($tracingId, $users::class));
        self::assertFalse($gateway->isCompletedFor($tracingId));
        $userService->run('other_branch', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertTrue($gateway->isCompletedFor($tracingId, $users::class));
    }

    private function consumeThroughConsole(FlowTestSupport $service, string $channel): void
    {
        $service->getGateway(ConsoleCommandRunner::class)->execute('ecotone:run', ['consumerName' => $channel, 'finishWhenNoMessages' => true, 'executionTimeLimit' => 1000]);
    }

    private function userHandlers(): object
    {
        return new class () {
            #[Distributed]
            #[InternalHandler('ticket.created')]
            public function created(string $ticket): void
            {
            }
        };
    }

    private function ticketHandlers(): object
    {
        return new class () {
            #[Distributed]
            #[InternalHandler('ticket.create')]
            public function create(string $description): string
            {
                return 'ticket for: ' . $description;
            }
        };
    }

    /**
     * @param list<object> $moreHandlers
     * @param list<object> $moreExtensions
     */
    private function service(string $serviceName, object $handlers, string $targetChannel, string $ownChannel, ?FlowTrackingConfiguration $flowTracking, object $defaultConnection, array $moreHandlers = [], array $moreExtensions = []): FlowTestSupport
    {
        $allHandlers = [$handlers, ...$moreHandlers];
        $targetService = $serviceName === 'user_service' ? 'ticket_service' : 'user_service';

        return $this->bootstrapFlowTesting(
            classesToResolve: array_map(static fn (object $handler) => $handler::class, $allHandlers),
            containerOrAvailableServices: [...$allHandlers, DbalConnectionFactory::class => $defaultConnection, self::CHANNELS => $this->getConnectionFactory()],
            extensionObjects: array_values(array_filter([
                DbalBackedMessageChannelBuilder::create($targetChannel, self::CHANNELS),
                DbalBackedMessageChannelBuilder::create($ownChannel, self::CHANNELS),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: $targetService, channelName: $targetChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName($serviceName),
                $flowTracking,
                ...$moreExtensions,
            ])),
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
