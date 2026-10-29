<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\MermaidDiagram;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;

/**
 * @internal
 */
final class FlowGraphRoutesTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_a_send_through_a_helper_reaches_the_distributed_command_and_its_reply_the_reply_handler(): void
    {
        $this->configurePulseWithServices('storefront', 'ticketing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['storefront' => $this->storefrontSendingThroughAHelper(), 'ticketing' => $this->ticketingAnsweringHolds()]);

        $diagram = MermaidDiagram::fromPage($client->request('GET', '/flow'));

        self::assertTrue($diagram->hasEdge('storefront', '::holdRequestedSeat', 'distributed message', 'ticketing', 'tickets.hold', '==>'));
        self::assertTrue($diagram->hasEdge('ticketing', '::hold', 'reply', 'storefront', 'Channel<br/>tickets.hold.result', '-.->'));
        self::assertTrue($diagram->hasEdge('storefront', 'Channel<br/>tickets.hold.result', '', 'storefront', '::whenHoldAnswered', '-->'));
        self::assertTrue($diagram->hasEdge('ticketing', '::hold', 'replies', 'ticketing', 'Distributed Bus', '-->'));
    }

    public function test_following_a_distributed_command_starts_at_the_sender_and_comes_back_through_the_reply_handler(): void
    {
        $this->configurePulseWithServices('storefront', 'ticketing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['storefront' => $this->storefrontSendingThroughAHelper(), 'ticketing' => $this->ticketingAnsweringHolds()]);

        $labels = $this->labelsByService(MermaidDiagram::fromPage($client->request('GET', '/flow?focus=' . urlencode('ticketing:message:tickets.hold'))));

        self::assertContains('EventHandler<br/>class@anonymous::holdRequestedSeat', $labels['storefront']);
        self::assertContains('Channel<br/>tickets.hold.result', $labels['storefront']);
        self::assertContains('InternalHandler<br/>class@anonymous::whenHoldAnswered', $labels['storefront']);
        self::assertContains('CommandHandler<br/>class@anonymous::hold', $labels['ticketing']);
    }

    public function test_a_report_without_the_reply_channel_draws_the_reply_back_to_the_sending_handler(): void
    {
        $this->configurePulseWithServices('storefront', 'ticketing');
        $client = $this->pulseWebClient();
        $graph = $this->storefrontSendingThroughAHelper()->getMessageFlowGraph()->toArray();
        $graph['edges'] = array_map(function (array $edge): array {
            unset($edge['attributes']['replyChannel']);

            return $edge;
        }, $graph['edges']);
        $this->pulseHavingHeardFrom($client, ['storefront' => $this->serviceAnsweringWith('storefront', $this->snapshotOf('storefront', $graph)), 'ticketing' => $this->ticketingAnsweringHolds()]);

        $diagram = MermaidDiagram::fromPage($client->request('GET', '/flow'));

        self::assertTrue($diagram->hasEdge('storefront', '::holdRequestedSeat', 'distributed message', 'ticketing', 'tickets.hold', '==>'));
        self::assertTrue($diagram->hasEdge('ticketing', '::hold', 'reply', 'storefront', '::holdRequestedSeat', '-.->'));
    }

    public function test_a_report_carrying_an_edge_kind_this_pulse_does_not_know_keeps_the_rest_of_that_service_graph(): void
    {
        $this->configurePulseWithServices('storefront', 'ticketing');
        $client = $this->pulseWebClient();
        $graph = $this->storefrontSendingThroughAHelper()->getMessageFlowGraph()->toArray();
        $graph['edges'][] = ['from' => $graph['nodes'][0]['id'], 'to' => $graph['nodes'][1]['id'], 'kind' => 'teleportsTo', 'attributes' => []];
        $this->pulseHavingHeardFrom($client, ['storefront' => $this->serviceAnsweringWith('storefront', $this->snapshotOf('storefront', $graph)), 'ticketing' => $this->ticketingAnsweringHolds()]);

        $diagram = MermaidDiagram::fromPage($client->request('GET', '/flow'));

        self::assertTrue($diagram->hasEdge('storefront', '::holdRequestedSeat', 'distributed message', 'ticketing', 'tickets.hold', '==>'));
        self::assertStringNotContainsString('teleportsTo', $diagram->source);
    }

    public function test_following_an_event_reaches_the_projection_that_reads_it(): void
    {
        $this->configurePulseWithServices('ticketing');
        $client = $this->pulseWebClient();
        $handlers = new class () {
            #[CommandHandler('tickets.hold', endpointId: 'tickets.hold.handler')]
            public function hold(array $payload, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('seat.held', $payload);
            }
        };
        $projection = new #[Projection('concert_seat_map'), FromStream('seats')] class () {
            #[EventHandler('seat.held', endpointId: 'concert_seat_map.seat_held')]
            public function seatHeld(array $event): void
            {
            }
        };
        $this->pulseHavingHeardFrom($client, ['ticketing' => $this->distributedService('ticketing', $handlers, DistributedServiceMap::initialize(), [], [$projection])]);

        $diagram = MermaidDiagram::fromPage($client->request('GET', '/flow?focus=' . urlencode('ticketing:message:seat.held')));

        self::assertTrue($diagram->hasEdge('ticketing', '::seatHeld', 'projects into', 'ticketing', 'concert_seat_map', '-->'));
        self::assertContains('concert_seat_map', $this->labelsByService($diagram)['ticketing']);
    }

    private function storefrontSendingThroughAHelper(): FlowTestSupport
    {
        $storefront = new class () {
            #[EventHandler('seat.requested', endpointId: 'hold_requested_seat')]
            public function holdRequestedSeat(array $event, #[Reference] DistributedBus $distributedBus): void
            {
                $this->send($distributedBus, 'tickets.hold', ['seatId' => $event['seatId']], replyRoutingKey: 'tickets.hold.result');
            }

            #[InternalHandler('tickets.hold.result', endpointId: 'tickets.hold.result.handler')]
            public function whenHoldAnswered(array $result): void
            {
            }

            private function send(DistributedBus $distributedBus, string $routingKey, array $payload, ?string $replyRoutingKey): void
            {
                $distributedBus->sendMessage(
                    targetServiceName: 'ticketing',
                    routingKey: $routingKey,
                    payload: json_encode($payload, JSON_THROW_ON_ERROR),
                    sourceMediaType: 'application/json',
                    replyRoutingKey: $replyRoutingKey,
                );
            }
        };

        return $this->distributedService('storefront', $storefront, DistributedServiceMap::initialize()->withCommandMapping('ticketing', $this->serviceChannels['ticketing']));
    }

    private function ticketingAnsweringHolds(): FlowTestSupport
    {
        $ticketing = new class () {
            #[Distributed]
            #[CommandHandler('tickets.hold', endpointId: 'tickets.hold.handler')]
            public function hold(array $payload): array
            {
                return ['outcome' => 'held'];
            }
        };

        return $this->distributedService('ticketing', $ticketing, DistributedServiceMap::initialize());
    }

    /**
     * @param array<string, mixed> $flowGraph
     */
    private function snapshotOf(string $service, array $flowGraph): string
    {
        return json_encode([
            'schemaVersion' => 1,
            'serviceName' => $service,
            'takenAt' => gmdate('c'),
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => PHP_VERSION, 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => $flowGraph,
            'topology' => ['serviceMaps' => []],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, list<string>>
     */
    private function labelsByService(MermaidDiagram $diagram): array
    {
        $labels = [];
        foreach ($diagram->nodes as $node) {
            $labels[$node['service']][] = (string) preg_replace('/_\d+$/', '', $node['label']);
        }

        return $labels;
    }
}
