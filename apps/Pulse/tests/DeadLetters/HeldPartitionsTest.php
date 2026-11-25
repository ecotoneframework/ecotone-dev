<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class HeldPartitionsTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    private object $shipments;

    public function test_the_failed_messages_page_lists_the_partition_keys_a_service_holds(): void
    {
        $client = $this->pulseWithHeldPartitionKeys();

        $held = $this->freshView($client, '/dead-letters')->filter('[data-held-partition][data-service="orders"]');

        self::assertSame(['o-1', 'o-2'], $held->each(static fn (Crawler $row) => $row->attr('data-partition-key')));
        self::assertSame('2 waiting', $held->eq(0)->filter('[data-held-messages]')->text());
        self::assertSame('Replay 2 messages of this key in order', $held->eq(0)->filter('[data-held-replay]')->text());
    }

    public function test_the_held_partition_keys_say_that_order_is_kept_within_one_partition_key_only(): void
    {
        $client = $this->pulseWithHeldPartitionKeys();

        $summary = $this->freshView($client, '/dead-letters')->filter('[data-held-partitions] summary')->text();

        self::assertStringContainsString('Order is kept within one partition key', $summary);
        self::assertStringContainsString('other failed messages are replayed in no particular order', $summary);
    }

    public function test_a_failed_message_shows_the_partition_key_holding_it_and_its_place_among_the_messages_of_that_key(): void
    {
        $client = $this->pulseWithHeldPartitionKeys();

        $places = $this->freshView($client, '/dead-letters')->filter('[data-dead-letter] [data-entry-partition]')->each(static fn (Crawler $cell) => $cell->text());
        sort($places);

        self::assertSame(['o-1 · 1 of 2, replayed first', 'o-1 · 2 of 2, follows the first', 'o-2 · 1 of 1, replayed first'], $places);
    }

    public function test_a_failure_group_with_held_messages_says_its_replay_starts_each_partition_key_with_its_oldest_message(): void
    {
        $client = $this->pulseWithHeldPartitionKeys();

        $notes = $this->freshView($client, '/dead-letters')->filter('[data-group-held-note]')->each(static fn (Crawler $note) => $note->text());

        self::assertSame([
            'All 2 listed messages belong to a held partition key: a replay starts each key with its oldest message, and the rest follow in order.',
            'The listed message belongs to a held partition key: a replay starts each key with its oldest message, and the rest follow in order.',
        ], $notes);
    }

    public function test_replaying_a_held_partition_key_handles_its_messages_in_the_order_they_failed(): void
    {
        $client = $this->pulseWithHeldPartitionKeys();
        $list = $this->freshView($client, '/dead-letters');
        $this->shipments->failing = false;

        $client->request('POST', '/dead-letters/actions', ['scope' => 'ids', 'replayPartition' => $list->filter('[data-held-partition][data-partition-key="o-1"] [data-held-replay]')->attr('value')]);
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);

        self::assertSame([1, 3], $this->shipments->handled);
        self::assertSame(['o-2'], $this->freshView($client, '/dead-letters')->filter('[data-held-partition]')->each(static fn (Crawler $row) => $row->attr('data-partition-key')));
    }

    public function test_deleting_a_held_partition_key_asks_to_confirm_and_then_removes_its_failed_messages(): void
    {
        $client = $this->pulseWithHeldPartitionKeys();
        $list = $this->freshView($client, '/dead-letters');

        $confirm = $client->request('POST', '/dead-letters/actions', ['scope' => 'ids', 'deletePartition' => $list->filter('[data-held-partition][data-partition-key="o-1"] [data-held-delete]')->attr('value')]);
        self::assertStringContainsString('This deletes the 2 failed messages you selected in orders.', $confirm->filter('[data-confirm-delete]')->text());
        $client->submit($confirm->filter('[data-confirm-delete]')->form());
        $this->deliver($client);

        $after = $this->freshView($client, '/dead-letters');
        self::assertSame(['o-2'], $after->filter('[data-held-partition]')->each(static fn (Crawler $row) => $row->attr('data-partition-key')));
        self::assertCount(1, $after->filter('[data-dead-letter]'));
    }

    private function pulseWithHeldPartitionKeys(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->shipments = new class () {
            public bool $failing = true;

            /**
             * @var list<int>
             */
            public array $handled = [];

            #[PartitionDeadLetter(partitionHeaderName: 'heldBy')]
            #[Asynchronous('pulse_dead_letter_work')]
            #[CommandHandler('order.dispatch', endpointId: 'pulse.order.dispatch.endpoint')]
            public function dispatch(array $order): void
            {
                if ($this->failing) {
                    throw new RuntimeException('dispatch failed for order ' . $order['orderId']);
                }

                $this->handled[] = $order['orderId'];
            }
        };
        $orders = $this->serviceWithDeadLetters('orders', additionalHandlers: [$this->shipments]);
        $this->failOrderOf($orders, 'order.dispatch', 1, 'o-1');
        $this->failOrderOf($orders, 'order.dispatch', 3, 'o-1');
        $this->failOrderOf($orders, 'order.dispatch', 2, 'o-2');

        return $client;
    }

    private function failOrderOf(FlowTestSupport $service, string $routingKey, int $orderId, string $partitionKey): void
    {
        $service->sendCommandWithRouting($routingKey, ['orderId' => $orderId], metadata: ['heldBy' => $partitionKey]);
        $service->run(self::WORK_CHANNEL, ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    private function freshView(KernelBrowser $client, string $uri): Crawler
    {
        $client->request('POST', '/refresh');
        $this->deliver($client);

        return $client->request('GET', $uri);
    }

    private function deliver(KernelBrowser $client): void
    {
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());
    }
}
