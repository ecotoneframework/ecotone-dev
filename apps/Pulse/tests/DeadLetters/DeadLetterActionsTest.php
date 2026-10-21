<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class DeadLetterActionsTest extends WebTestCase
{
    use DeadLetterServicesTrait;

    public function test_replaying_selected_dead_letters_runs_their_handlers_again_and_removes_them_from_the_next_snapshot(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->work->failing = false;

        $this->post($client, ['action' => 'replay', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.place')]]);
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);

        self::assertEqualsCanonicalizing(['order.place:1', 'order.place:3'], $this->work->handled);
        $after = $client->request('GET', '/dead-letters');
        self::assertCount(1, $after->filter('[data-dead-letter]'));
        self::assertSame('order.cancel', $after->filter('[data-dead-letter]')->attr('data-message-name'));
    }

    public function test_replaying_everything_matching_a_filter_replays_only_the_matching_messages(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');
        $this->work->failing = false;

        $this->post($client, ['action' => 'replay', 'scope' => 'filter', 'service' => 'orders', 'messageName' => 'order.place']);
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);

        self::assertEqualsCanonicalizing(['order.place:1', 'order.place:3'], $this->work->handled);
        self::assertSame(['order.cancel'], $this->remainingNames($client));
    }

    public function test_replaying_to_the_asynchronous_channel_of_the_handler_runs_the_handler_once_the_channel_is_consumed(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->work->failing = false;

        $this->post($client, ['action' => 'replay', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')], 'targetChannel' => ['orders' => self::WORK_CHANNEL]]);
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);

        self::assertSame(['order.cancel:2'], $this->work->handled);
        self::assertSame(['order.place', 'order.place'], $this->remainingNames($client));
    }

    public function test_replaying_to_another_asynchronous_channel_runs_the_original_handler_once_that_channel_is_consumed(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->work->failing = false;

        $this->post($client, ['action' => 'replay', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')], 'targetChannel' => ['orders' => self::OTHER_CHANNEL]]);
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);
        self::assertSame([], $this->work->handled);
        $this->replayOtherChannelOf($this->services['orders']);

        self::assertSame(['order.cancel:2'], $this->work->handled);
        self::assertSame(['order.place', 'order.place'], $this->remainingNames($client));
    }

    public function test_replaying_everything_matching_a_filter_of_one_service_to_a_chosen_channel_sends_the_messages_there(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');
        $this->work->failing = false;

        $this->post($client, ['action' => 'replay', 'scope' => 'filter', 'service' => 'orders', 'messageName' => 'order.cancel', 'targetChannel' => self::OTHER_CHANNEL]);
        $this->deliver($client);
        $this->replayOtherChannelOf($this->services['orders']);

        self::assertSame(['order.cancel:2'], $this->work->handled);
        self::assertSame(['order.place', 'order.place'], $this->remainingNames($client));
    }

    public function test_replaying_to_a_channel_the_service_does_not_register_is_refused(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->advanceTimeBy(1);

        $this->post($client, ['action' => 'replay', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')], 'targetChannel' => ['orders' => 'not_a_channel']]);
        $refused = $client->followRedirect();
        $this->deliver($client);

        self::assertStringContainsString('not_a_channel', $refused->filter('[data-notice-error]')->text());
        self::assertStringContainsString('orders', $refused->filter('[data-notice-error]')->text());
        self::assertCount(0, $refused->filter('[data-notice]'));
        self::assertSame([], $this->work->handled);
        self::assertCount(3, $client->request('GET', '/dead-letters')->filter('[data-dead-letter]'));
    }

    public function test_a_filter_replay_to_a_channel_the_chosen_service_does_not_register_is_refused(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');

        $this->post($client, ['action' => 'replay', 'scope' => 'filter', 'service' => 'orders', 'messageName' => 'order.cancel', 'targetChannel' => 'not_a_channel']);
        $refused = $client->followRedirect();

        self::assertStringContainsString('not_a_channel', $refused->filter('[data-notice-error]')->text());
    }

    public function test_a_filter_replay_across_services_cannot_name_a_target_channel(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');

        $this->post($client, ['action' => 'replay', 'scope' => 'filter', 'messageName' => 'order.cancel', 'targetChannel' => self::OTHER_CHANNEL]);
        $refused = $client->followRedirect();
        $this->deliver($client);

        self::assertStringContainsString('single service', $refused->filter('[data-notice-error]')->text());
        self::assertSame([], $this->work->handled);
    }

    public function test_the_channels_a_service_registers_are_offered_as_a_select_for_the_target_channel_of_that_service(): void
    {
        $client = $this->pulseWithFailures();

        $list = $this->freshView($client, '/dead-letters');

        $select = $list->filter('select[name="targetChannel[orders]"]');
        self::assertCount(1, $select);
        $options = $select->filter('option')->each(static fn (Crawler $option) => $option->attr('value'));
        self::assertSame('', $options[0]);
        self::assertContains(self::WORK_CHANNEL, $options);
        self::assertContains(self::OTHER_CHANNEL, $options);
        self::assertNotContains('order.place', $options);
        self::assertNotContains('order.audit', $options);
        self::assertNotContains($this->pulseChannel, $options);
        self::assertCount(0, $list->filter('input[name^="targetChannel"]'));
        self::assertCount(0, $list->filter('[data-filter-action-form] select'));
    }

    public function test_a_replay_target_posted_as_a_single_value_with_a_selection_is_refused_with_a_notice(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');

        $this->post($client, ['action' => 'replay', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')], 'targetChannel' => self::OTHER_CHANNEL]);
        $refused = $client->followRedirect();

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('own section', $refused->filter('[data-notice-error]')->text());
    }

    public function test_the_filter_replay_offers_the_channels_only_once_a_single_service_is_chosen(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');

        $list = $client->request('GET', '/dead-letters?service=orders');

        $options = $list->filter('[data-filter-action-form] select[name="targetChannel"] option')->each(static fn (Crawler $option) => $option->attr('value'));
        self::assertContains(self::OTHER_CHANNEL, $options);
    }

    public function test_a_replay_requests_one_more_snapshot_five_seconds_later_even_within_sixty_seconds_of_the_last_one(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->post($client, ['action' => 'replay', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')]]);
        $this->deliver($client);

        $this->advanceTimeBy(4);
        $client->request('GET', '/dead-letters');
        $this->deliver($client);
        self::assertSame('4s', $this->ageOnServicesPage($client, 'orders'));

        $this->advanceTimeBy(1);
        $client->request('GET', '/dead-letters');
        $this->deliver($client);
        self::assertSame('0s', $this->ageOnServicesPage($client, 'orders'));

        $this->advanceTimeBy(6);
        $client->request('GET', '/dead-letters');
        $this->deliver($client);
        self::assertSame('6s', $this->ageOnServicesPage($client, 'orders'));
    }

    public function test_the_page_keeps_refreshing_until_the_follow_up_snapshot_is_answered(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->post($client, ['action' => 'replay', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')]]);
        $this->deliver($client);

        self::assertCount(1, $client->request('GET', '/dead-letters')->filter('meta[http-equiv="refresh"]'));

        $this->advanceTimeBy(5);
        $client->request('GET', '/dead-letters');
        $this->deliver($client);

        self::assertCount(0, $client->request('GET', '/dead-letters')->filter('meta[http-equiv="refresh"]'));
    }

    public function test_a_delete_requests_no_follow_up_snapshot(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->post($client, ['action' => 'delete', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')]]);
        $this->deliver($client);

        $this->advanceTimeBy(5);
        $client->request('GET', '/dead-letters');
        $this->deliver($client);

        self::assertSame('5s', $this->ageOnServicesPage($client, 'orders'));
        self::assertCount(0, $client->request('GET', '/dead-letters')->filter('meta[http-equiv="refresh"]'));
    }

    public function test_the_sent_notice_says_a_refusing_service_shows_as_unreachable(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');

        $this->post($client, ['action' => 'delete', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')]]);
        $sent = $client->followRedirect();

        self::assertStringContainsString('unreachable after the reply timeout', $sent->filter('[data-notice]')->text());
    }

    public function test_deleting_selected_dead_letters_removes_them_without_running_their_handlers(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->work->failing = false;

        $this->post($client, ['action' => 'delete', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.place')]]);
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);

        self::assertSame([], $this->work->handled);
        self::assertSame(['order.cancel'], $this->remainingNames($client));
    }

    public function test_deleting_everything_matching_a_filter_asks_for_confirmation_first(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');

        $confirmation = $client->request('POST', '/dead-letters/actions', ['action' => 'delete', 'scope' => 'filter', 'service' => 'orders', 'exceptionClass' => 'RuntimeException']);
        $this->deliver($client);

        self::assertCount(1, $confirmation->filter('[data-confirm-delete]'));
        self::assertCount(3, $client->request('GET', '/dead-letters')->filter('[data-dead-letter]'));

        $client->submit($confirmation->filter('[data-confirm-delete] button')->form());
        $this->deliver($client);

        self::assertSame(['order.cancel'], $this->remainingNames($client));
    }

    public function test_a_sent_action_shows_a_notice_and_the_service_as_waiting_until_its_snapshot_arrives(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->advanceTimeBy(1);

        $this->post($client, ['action' => 'delete', 'scope' => 'ids', 'selected' => ['orders' => $this->selectionOf($list, 'orders', 'order.cancel')]]);
        $waiting = $client->followRedirect();

        self::assertStringContainsString('Action sent', $waiting->filter('[data-notice]')->text());
        self::assertStringContainsString('waiting', $waiting->filter('[data-service-section="orders"]')->text());
        $this->deliver($client);
        self::assertStringContainsString('connected', $client->request('GET', '/dead-letters')->filter('[data-service-section="orders"]')->text());
    }

    public function test_actions_are_not_offered_for_a_service_without_dead_letter_and_are_refused_for_it(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $this->advanceTimeBy(1);

        self::assertCount(0, $list->filter('[data-service-section="billing"] form'));

        $this->post($client, ['action' => 'replay', 'scope' => 'filter', 'service' => 'billing', 'messageName' => 'order.place']);
        $refused = $client->followRedirect();

        self::assertStringContainsString('billing', $refused->filter('[data-notice-error]')->text());
        self::assertStringContainsString('dead letter', $refused->filter('[data-notice-error]')->text());
        self::assertStringNotContainsString('waiting', $refused->filter('[data-service-section="billing"]')->text());
    }

    public function test_a_filter_action_without_any_condition_is_refused_instead_of_matching_everything(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');

        $this->post($client, ['action' => 'delete', 'scope' => 'filter', 'service' => 'orders', 'confirm' => '1']);
        $refused = $client->followRedirect();
        $this->deliver($client);

        self::assertStringContainsString('at least one filter condition', $refused->filter('[data-notice-error]')->text());
        self::assertCount(3, $client->request('GET', '/dead-letters')->filter('[data-dead-letter]'));
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
     * @param array<string, mixed> $fields
     */
    private function post(KernelBrowser $client, array $fields): void
    {
        $client->request('POST', '/dead-letters/actions', $fields);
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
    private function ageOnServicesPage(KernelBrowser $client, string $service): string
    {
        return $client->request('GET', '/')->filter(sprintf('[data-service="%s"] [data-age]', $service))->text();
    }

    private function remainingNames(KernelBrowser $client): array
    {
        $names = $client->request('GET', '/dead-letters')->filter('[data-dead-letter]')->each(static fn (Crawler $row) => $row->attr('data-message-name'));
        sort($names);

        return $names;
    }
}
