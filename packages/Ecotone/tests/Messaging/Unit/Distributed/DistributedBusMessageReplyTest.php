<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Distributed;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Test\ChannelTestSupport;
use Ecotone\Test\LicenceTesting;
use Ecotone\Test\StaticMessageBuilder;
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

    public function test_an_asynchronous_target_replies_once_its_work_is_consumed(): void
    {
        $channels = [...$this->channels(), SimpleMessageChannelBuilder::createQueueChannel('ticket_work')];
        $tickets = new class () {
            #[Asynchronous('ticket_work')]
            #[Distributed]
            #[InternalHandler('ticket.create', endpointId: 'ticket.creation')]
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
        $userService = $this->userService($users, $channels);
        $ticketService = $this->ticketService($tickets, $channels);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', replyToChannelName: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertSame([], $users->replies);

        $ticketService->run('ticket_work', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertSame(['ticket for: Printer is broken'], $users->replies);
    }

    public function test_the_reply_carries_the_request_metadata_and_correlation_id(): void
    {
        $channels = $this->channels();
        $tickets = new class () {
            public ?string $correlationId = null;

            #[Distributed]
            #[InternalHandler('ticket.create')]
            public function create(string $description, #[Header(MessageHeaders::MESSAGE_CORRELATION_ID)] string $correlationId): string
            {
                $this->correlationId = $correlationId;

                return 'ticket for: ' . $description;
            }
        };
        $users = new class () {
            public array $replies = [];

            #[InternalHandler('ticket.created')]
            public function created(string $ticket, #[Header('requestedBy')] string $requestedBy, #[Header(MessageHeaders::MESSAGE_CORRELATION_ID)] string $correlationId): void
            {
                $this->replies[] = [$ticket, $requestedBy, $correlationId];
            }
        };
        $userService = $this->userService($users, $channels);
        $ticketService = $this->ticketService($tickets, $channels);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', metadata: ['requestedBy' => 'johny'], replyToChannelName: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([['ticket for: Printer is broken', 'johny', $tickets->correlationId]], $users->replies);
    }

    public function test_a_handler_returning_null_sends_no_reply(): void
    {
        $channels = $this->channels();
        $tickets = new class () {
            #[Distributed]
            #[InternalHandler('ticket.create')]
            public function create(string $description): ?string
            {
                return null;
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
        $userService = $this->userService($users, $channels);
        $ticketService = $this->ticketService($tickets, $channels);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', replyToChannelName: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $users->replies);
    }

    public function test_a_reply_is_not_answered_again_even_when_the_reply_handler_returns_a_value(): void
    {
        $channels = $this->channels();
        $tickets = new class () {
            public int $created = 0;

            #[Distributed]
            #[InternalHandler('ticket.create')]
            public function create(string $description): string
            {
                $this->created++;

                return 'ticket for: ' . $description;
            }
        };
        $users = new class () {
            public array $replies = [];

            #[Distributed]
            #[InternalHandler('ticket.created')]
            public function created(string $ticket): string
            {
                $this->replies[] = $ticket;

                return 'acknowledged';
            }
        };
        $userService = $this->userService($users, $channels);
        $ticketService = $this->ticketService($tickets, $channels);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', replyToChannelName: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(1, $tickets->created);
        self::assertSame(['ticket for: Printer is broken'], $users->replies);
    }

    public function test_an_object_result_reaches_the_reply_to_channel_as_the_same_object(): void
    {
        $channels = $this->channels();
        $tickets = new class () {
            #[Distributed]
            #[InternalHandler('ticket.create')]
            public function create(string $description): TicketWasCreated
            {
                return new TicketWasCreated('ticket-1', $description);
            }
        };
        $users = new class () {
            public array $replies = [];

            #[InternalHandler('ticket.created')]
            public function created(TicketWasCreated $ticket): void
            {
                $this->replies[] = $ticket;
            }
        };
        $userService = $this->userService($users, $channels);
        $ticketService = $this->ticketService($tickets, $channels);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', replyToChannelName: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals([new TicketWasCreated('ticket-1', 'Printer is broken')], $users->replies);
    }

    public function test_sending_a_message_without_reply_to_channel_sends_no_reply(): void
    {
        $channels = $this->channels();
        $tickets = new class () {
            public array $descriptions = [];

            #[Distributed]
            #[InternalHandler('ticket.create')]
            public function create(string $description): string
            {
                $this->descriptions[] = $description;

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
        $userService = $this->userService($users, $channels);
        $ticketService = $this->ticketService($tickets, $channels);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['Printer is broken'], $tickets->descriptions);
        self::assertSame([], $users->replies);
    }

    public function test_two_service_maps_mapping_the_sender_to_the_same_channel_send_one_reply(): void
    {
        $channels = $this->channels();
        $users = new class () {
            public array $replies = [];

            #[InternalHandler('ticket.created')]
            public function created(string $ticket): void
            {
                $this->replies[] = $ticket;
            }
        };
        $userService = $this->userService($users, $channels);
        $ticketService = $this->service(
            'ticket_service',
            $this->ticketsReturningDescription(),
            $channels,
            DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'user_service', channelName: 'user_channel'),
            DistributedServiceMap::initialize('second_distributed_bus')->withCommandMapping(targetServiceName: 'user_service', channelName: 'user_channel'),
        );

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', replyToChannelName: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['ticket for: Printer is broken'], $users->replies);
    }

    public function test_a_reply_is_refused_when_no_service_map_of_the_receiver_maps_the_sender(): void
    {
        $channels = $this->channels();
        $userService = $this->userService(new class () {
        }, $channels);
        $ticketService = $this->service('ticket_service', $this->ticketsReturningDescription(), $channels);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', replyToChannelName: 'ticket.created');

        $this->expectExceptionMessage("A reply to channel 'ticket.created' of service 'user_service' cannot be sent, because no Service Map in service 'ticket_service' maps service 'user_service' to a channel. Register the channel 'user_service' consumes with DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'user_service', channelName: '...') in service 'ticket_service', or send the message without replyToChannelName.");

        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_reply_is_refused_when_two_service_maps_of_the_receiver_map_the_sender_to_different_channels(): void
    {
        $channels = [...$this->channels(), SimpleMessageChannelBuilder::createQueueChannel('user_channel_2')];
        $userService = $this->userService(new class () {
        }, $channels);
        $ticketService = $this->service(
            'ticket_service',
            $this->ticketsReturningDescription(),
            $channels,
            DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'user_service', channelName: 'user_channel'),
            DistributedServiceMap::initialize('second_distributed_bus')->withCommandMapping(targetServiceName: 'user_service', channelName: 'user_channel_2'),
        );

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', replyToChannelName: 'ticket.created');

        $this->expectExceptionMessage("A reply to channel 'ticket.created' of service 'user_service' cannot be sent, because Service Maps in service 'ticket_service' map service 'user_service' to different channels: 'Ecotone\\Api\\Gateway\\DistributedBus' to 'user_channel', 'second_distributed_bus' to 'user_channel_2'. Map service 'user_service' in one Service Map only, or to the same channel in each.");

        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_reply_is_refused_when_the_message_names_no_source_service(): void
    {
        $channels = $this->channels();
        $ticketService = $this->ticketService($this->ticketsReturningDescription(), $channels);
        ChannelTestSupport::of($ticketService)->getMessageChannel('ticket_channel')->send(
            StaticMessageBuilder::withPayload('Printer is broken')
                ->setHeader(MessageHeaders::ROUTING_SLIP, DistributedBusHeader::DISTRIBUTED_ROUTING_SLIP_VALUE)
                ->setHeader(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY, 'ticket.create')
                ->setHeader(DistributedBusHeader::DISTRIBUTED_PAYLOAD_TYPE, 'message')
                ->setHeader(DistributedBusHeader::DISTRIBUTED_TARGET_SERVICE_NAME, 'ticket_service')
                ->setHeader(DistributedBusHeader::DISTRIBUTED_REPLY_TO_CHANNEL, 'ticket.created')
                ->setHeader(MessageHeaders::CONTENT_TYPE, MediaType::TEXT_PLAIN)
                ->build()
        );

        $this->expectExceptionMessage("A reply to channel 'ticket.created' cannot be sent, because the message names no source service in header '" . DistributedBusHeader::DISTRIBUTED_SOURCE_SERVICE_NAME . "', so there is no service to reply to. Send the message with DistributedBus::sendMessage(), which sets that header, or set it to the name of the sending service.");

        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
    }

    private function ticketsReturningDescription(): object
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

    private function channels(): array
    {
        return [SimpleMessageChannelBuilder::createQueueChannel('ticket_channel'), SimpleMessageChannelBuilder::createQueueChannel('user_channel')];
    }

    private function userService(object $handlers, array $channels): FlowTestSupport
    {
        return $this->service('user_service', $handlers, $channels, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel'));
    }

    private function ticketService(object $handlers, array $channels): FlowTestSupport
    {
        return $this->service('ticket_service', $handlers, $channels, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'user_service', channelName: 'user_channel'));
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

final class TicketWasCreated
{
    public function __construct(public readonly string $ticketId, public readonly string $description)
    {
    }
}
