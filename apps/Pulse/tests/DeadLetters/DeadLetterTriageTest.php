<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Test\StaticMessageBuilder;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class DeadLetterTriageTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_the_navigation_badge_counts_failures_not_dead_letters(): void
    {
        $client = $this->pulseWithFailures();

        $list = $this->freshView($client, '/dead-letters');

        self::assertSame('2 failures', $list->filter('nav [data-nav-dead-letters]')->text());
        self::assertSame('2 failures', $client->request('GET', '/services')->filter('nav [data-nav-dead-letters]')->text());
    }

    public function test_the_navigation_has_no_badge_without_dead_letters(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->serviceWithDeadLetters('orders');

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertCount(0, $crawler->filter('nav [data-nav-dead-letters]'));
    }

    public function test_groups_offer_replaying_all_of_their_dead_letters(): void
    {
        $client = $this->pulseWithFailures();

        $list = $this->freshView($client, '/dead-letters');

        $group = $list->filter('[data-group][data-service="orders"][data-message-name="order.place"]');
        self::assertCount(1, $group);
        self::assertSame('Replay all 2', trim($group->filter('[data-group-replay] button')->text()));
        self::assertSame('Replay all 1', trim($list->filter('[data-group][data-service="orders"][data-message-name="order.cancel"] [data-group-replay] button')->text()));
    }

    public function test_replaying_all_of_a_group_replays_only_that_exception_and_message_name(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');

        $client->submit($list->filter('[data-group][data-message-name="order.place"] [data-group-replay] button')->form());
        $this->deliver($client);

        self::assertSame(['order.cancel'], $this->remainingNames($client));
    }

    public function test_the_list_can_be_searched_by_message_id(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $messageId = $list->filter('[data-dead-letter][data-message-name="order.cancel"]')->attr('data-message-id');

        $found = $client->request('GET', '/dead-letters', ['search' => substr($messageId, 4, 12)]);

        self::assertCount(1, $found->filter('[data-dead-letter]'));
        self::assertSame($messageId, $found->filter('[data-dead-letter]')->attr('data-message-id'));
    }

    public function test_the_list_can_be_searched_by_payload(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');

        $found = $client->request('GET', '/dead-letters', ['search' => 'i:2;']);

        self::assertCount(1, $found->filter('[data-dead-letter]'));
        self::assertSame('order.cancel', $found->filter('[data-dead-letter]')->attr('data-message-name'));
    }

    public function test_a_filter_action_is_refused_while_a_search_narrows_the_list(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');

        $client->request('POST', '/dead-letters/actions', ['action' => 'replay', 'scope' => 'filter', 'service' => 'orders', 'exceptionClass' => 'RuntimeException', 'search' => 'i:1;']);
        $this->deliver($client);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('search', $crawler->filter('[data-notice-error]')->text());
        self::assertSame(['order.cancel', 'order.place', 'order.place'], $this->remainingNames($client));
    }

    public function test_every_filter_control_has_a_visible_label(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        $controls = $crawler->filter('[data-filter-form] input:not([type="hidden"]), [data-filter-form] select');
        self::assertGreaterThanOrEqual(6, $controls->count());
        foreach ($controls as $control) {
            self::assertGreaterThan(0, $crawler->filter(sprintf('[data-filter-form] label[for="%s"]', $control->getAttribute('id')))->count());
        }
    }

    public function test_a_sent_action_ends_with_a_toast_naming_the_result(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');

        $client->request('POST', '/dead-letters/actions', ['action' => 'replay', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')]]);
        $crawler = $client->followRedirect();

        self::assertSame('status', $crawler->filter('[data-notice]')->attr('role'));
        self::assertStringContainsString('replay', $crawler->filter('[data-notice]')->text());
    }

    public function test_the_selection_bar_sits_beside_the_selection_and_keeps_the_buttons_without_javascript(): void
    {
        $client = $this->pulseWithFailures();

        $list = $this->freshView($client, '/dead-letters');

        self::assertCount(1, $list->filter('[data-selection-bar] button[value="replay"]'));
        self::assertCount(1, $list->filter('[data-selection-bar] button[value="delete"]'));
        self::assertCount(1, $list->filter('[data-selection-form][id="selection"]'));
        self::assertCount(3, $list->filter('[data-dead-letter] input[type="checkbox"][form="selection"]'));
    }

    public function test_the_detail_replays_and_deletes_that_one_dead_letter(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $detail = $client->click($list->filter('[data-dead-letter][data-message-name="order.cancel"] a')->link());

        self::assertCount(1, $detail->filter('[data-detail-actions] button[value="replay"]'));
        self::assertCount(1, $detail->filter('[data-detail-actions] button[value="delete"]'));
        $client->submit($detail->filter('[data-detail-actions] button[value="replay"]')->form());
        $this->deliver($client);

        self::assertSame(['order.place', 'order.place'], $this->remainingNames($client));
    }

    public function test_the_detail_links_to_dead_letters_with_the_same_failure_and_offers_copying(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $detail = $client->click($list->filter('[data-dead-letter][data-message-name="order.place"] a')->link());

        self::assertSame('1 more with this failure', trim($detail->filter('[data-similar]')->text()));
        self::assertStringContainsString('failureId=', $detail->filter('[data-similar]')->attr('href'));
        self::assertGreaterThanOrEqual(2, $detail->filter('[data-copy]')->count());
        self::assertCount(1, $detail->filter('details[data-stack-trace]'));
    }

    public function test_a_json_payload_is_shown_pretty_printed(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceWithDeadLetters('orders');
        $orders->sendCommandWithRouting('order.cancel', '{"orderId":2}', 'application/json');
        $orders->run(self::WORK_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(10, 100, false)->withMemoryLimitInMegabytes(4096));
        $list = $this->freshView($client, '/dead-letters');

        $detail = $client->click($list->filter('[data-dead-letter] a')->link());

        self::assertStringContainsString("{\n    \"orderId\": 2\n}", $detail->filter('[data-detail="payload"]')->html());
    }

    public function test_a_named_and_an_unnamed_failure_both_say_replay_all_with_their_stored_count(): void
    {
        $client = $this->pulseWithUnnamedFailures();

        $list = $this->freshView($client, '/dead-letters');

        self::assertSame('Replay all 2', trim($list->filter('[data-group][data-message-name="order.place"] [data-group-replay] button')->text()));
        self::assertSame('Replay all 2', trim($list->filter('[data-group][data-message-name=""] [data-group-replay] button')->text()));
    }

    public function test_replaying_an_unnamed_group_replays_those_messages_and_never_the_named_ones_of_the_same_exception(): void
    {
        $client = $this->pulseWithUnnamedFailures();
        $list = $this->freshView($client, '/dead-letters');

        $client->submit($list->filter('[data-group][data-message-name=""] [data-group-replay] button')->form());
        $this->deliver($client);

        self::assertSame(['order.cancel', 'order.place', 'order.place'], $this->remainingNames($client));
    }

    public function test_an_unnamed_failure_narrows_the_list_by_its_failure_not_by_the_whole_exception(): void
    {
        $client = $this->pulseWithUnnamedFailures();

        $list = $this->freshView($client, '/dead-letters');

        self::assertCount(0, $list->filter('[data-group][data-message-name=""] a[href*="exceptionClass"]'));
        self::assertCount(1, $list->filter('[data-group][data-message-name=""] a[data-only-failure][href*="failureId="]'));
    }

    public function test_replaying_a_group_honours_the_replay_channel_chosen_for_the_service(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->work->failing = false;

        $form = $list->filter('[data-group][data-message-name="order.place"] [data-group-replay] button')->form();
        $form['targetChannel']['orders']->select(self::OTHER_CHANNEL);
        $client->submit($form);
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);
        self::assertSame([], $this->work->handled);
        $this->replayOtherChannelOf($this->services['orders']);

        $handled = $this->work->handled;
        sort($handled);
        self::assertSame(['order.place:1', 'order.place:3'], $handled);
    }

    public function test_the_count_of_a_group_is_what_the_action_replays_even_when_the_list_is_narrowed(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $messageId = $list->filter('[data-dead-letter][data-message-name="order.place"]')->attr('data-message-id');

        $narrowed = $client->request('GET', '/dead-letters', ['search' => $messageId]);

        self::assertSame('Replay all 2', trim($narrowed->filter('[data-group][data-message-name="order.place"] [data-group-replay] button')->text()));
        self::assertStringContainsString('1 of 2 listed', $narrowed->filter('[data-group][data-message-name="order.place"]')->text());
        $client->submit($narrowed->filter('[data-group-replay] button')->form());
        $this->deliver($client);
        self::assertSame(['order.cancel'], $this->remainingNames($client));
    }

    public function test_deleting_the_selection_from_the_bar_asks_for_confirmation_and_deletes_nothing_yet(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $before = $this->remainingNames($client);
        $form = $list->filter('[data-selection-bar] button[value="delete"]')->form();
        $form['selected']['orders'][0]->tick();

        $confirmation = $client->submit($form);
        $this->deliver($client);

        self::assertCount(1, $confirmation->filter('[data-confirm-delete]'));
        self::assertSame($before, $this->remainingNames($client));
    }

    private function pulseWithUnnamedFailures(): KernelBrowser
    {
        $client = $this->pulseWithFailures();
        $deadLetters = $this->services['orders']->getGateway(DeadLetterGateway::class);
        foreach (['unnamed-1', 'unnamed-2'] as $index => $id) {
            $deadLetters->store(StaticMessageBuilder::withPayload('x')->setMultipleHeaders([
                MessageHeaders::MESSAGE_ID => $id,
                MessageHeaders::TIMESTAMP => 1_760_000_000 + $index,
                MessageHeaders::POLLED_CHANNEL_NAME => self::WORK_CHANNEL,
                ErrorContext::EXCEPTION_CLASS => RuntimeException::class,
                ErrorContext::EXCEPTION_MESSAGE => 'boom',
                ErrorContext::EXCEPTION_STACKTRACE => '#0',
                ErrorContext::EXCEPTION_FILE => 'f.php',
                ErrorContext::EXCEPTION_LINE => 1,
                ErrorContext::EXCEPTION_CODE => 0,
            ])->build());
        }

        return $client;
    }

    private function pulseWithFailures(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceWithDeadLetters('orders');
        $this->failOrder($orders, 'order.place', 1);
        $this->failOrder($orders, 'order.cancel', 2);
        $this->failOrder($orders, 'order.place', 3);
        $this->serviceWithoutDeadLetters('billing');

        return $client;
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

    /**
     * @return list<string>
     */
    private function selectionOf(Crawler $list, string $service, string $messageName): array
    {
        return $list->filter(sprintf('[data-dead-letter][data-service="%1$s"][data-message-name="%2$s"] input[name="selected[%1$s][]"]', $service, $messageName))->each(static fn (Crawler $box) => $box->attr('value'));
    }

    /**
     * @return list<string>
     */
    private function remainingNames(KernelBrowser $client): array
    {
        $names = $client->request('GET', '/dead-letters')->filter('[data-dead-letter]')->each(static fn (Crawler $row) => $row->attr('data-message-name'));
        sort($names);

        return $names;
    }
}
