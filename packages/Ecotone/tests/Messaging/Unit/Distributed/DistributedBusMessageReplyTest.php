<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Distributed;

use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

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
final class DistributedBusMessageReplyTest extends TestCase
{
    public function test_the_result_of_a_distributed_message_triggers_the_reply_to_channel_in_the_sending_service(): void
    {
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('ticket_channel'), SimpleMessageChannelBuilder::createQueueChannel('user_channel')];
        $tickets = new class () {
            #[Distributed]
            #[InternalHandler('ticket.create')]
            public function create(string $description): string
            {
                return 'ticket for: ' . $description;
            }
        };
        $users = new class () {
            public array $replies = [];

            #[InternalHandler('ticket.created')]
            public function created(string $ticket): void
            {
                $this->replies[] = $ticket;
            }
        };
        $userService = $this->service('user_service', $users, $channels, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel'));
        $ticketService = $this->service('ticket_service', $tickets, $channels, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'user_service', channelName: 'user_channel'));

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', replyToChannelName: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['ticket for: Printer is broken'], $users->replies);
    }

    private function service(string $serviceName, object $handlers, array $channels, DistributedServiceMap ...$serviceMaps): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handlers::class],
            containerOrAvailableServices: [$handlers],
            extensionObjects: [...$channels, ...$serviceMaps, ServiceConfiguration::createWithDefaults()->withServiceName($serviceName)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
