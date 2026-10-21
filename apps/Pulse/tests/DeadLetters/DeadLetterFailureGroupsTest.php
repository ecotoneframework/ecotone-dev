<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class DeadLetterFailureGroupsTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_a_failure_leads_with_what_went_wrong_where_and_for_which_message(): void
    {
        $client = $this->pulseWithSharedException();

        $list = $this->freshView($client, '/dead-letters');

        $failure = $list->filter('[data-group][data-message-name="order.ship"]');
        self::assertCount(1, $failure);
        self::assertSame('RuntimeException', $failure->filter('[data-failure-class]')->text());
        self::assertMatchesRegularExpression('/^DeadLetterServicesTrait\.php:\d+$/', $failure->filter('[data-failure-location]')->text());
        self::assertStringContainsString('/DeadLetterServicesTrait.php:', $failure->filter('[data-failure-location]')->attr('title'));
        self::assertMatchesRegularExpression('/^no stock for order [12]$/', $failure->filter('[data-failure-message]')->text());
        self::assertSame('order.ship', $failure->filter('[data-failure-message-name]')->text());
        self::assertSame('2 failed', $failure->filter('[data-failure-count]')->text());
        self::assertStringContainsString(gmdate('Y-m-d'), $failure->filter('[data-failure-seen]')->text());
    }

    public function test_the_same_exception_thrown_at_the_same_place_for_two_message_names_is_two_failures(): void
    {
        $client = $this->pulseWithSharedException();

        $list = $this->freshView($client, '/dead-letters');

        self::assertCount(2, $list->filter('[data-group]'));
        self::assertNotSame(
            $list->filter('[data-group][data-message-name="order.ship"]')->attr('data-failure'),
            $list->filter('[data-group][data-message-name="order.reserve"]')->attr('data-failure'),
        );
        self::assertSame('2', $list->filter('[data-service-failures="orders"]')->text());
    }

    public function test_replaying_all_of_a_failure_leaves_the_same_exception_of_another_message_name_untouched(): void
    {
        $client = $this->pulseWithSharedException();
        $list = $this->freshView($client, '/dead-letters');
        $this->work->failing = false;

        $client->submit($list->filter('[data-group][data-message-name="order.ship"] [data-group-replay] button')->form());
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);

        self::assertEqualsCanonicalizing(['order.ship:1', 'order.ship:2'], $this->work->handled);
        self::assertSame(['order.reserve'], $this->remainingNames($client));
    }

    public function test_replaying_all_of_a_failure_names_the_failure_while_the_service_has_not_answered_yet(): void
    {
        $client = $this->pulseWithSharedException();
        $list = $this->freshView($client, '/dead-letters');
        $this->work->failing = false;

        $client->submit($list->filter('[data-group][data-message-name="order.ship"] [data-group-replay] button')->form());
        $notice = $client->followRedirect();
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);

        self::assertCount(0, $notice->filter('[data-notice] [data-affected]'));
        self::assertStringContainsString('for the failure RuntimeException at DeadLetterServicesTrait.php:', $notice->filter('[data-notice]')->text());
        self::assertStringContainsString('for order.ship', $notice->filter('[data-notice]')->text());
    }

    public function test_deleting_all_of_a_failure_asks_for_confirmation_then_deletes_only_that_failure(): void
    {
        $client = $this->pulseWithSharedException();
        $list = $this->freshView($client, '/dead-letters');

        $confirmation = $client->submit($list->filter('[data-group][data-message-name="order.ship"] [data-group-delete]')->form());
        $this->deliver($client);

        self::assertCount(1, $confirmation->filter('[data-confirm-delete]'));
        self::assertStringContainsString('order.ship', $confirmation->filter('[data-failure-label]')->text());
        self::assertSame(['order.reserve', 'order.ship', 'order.ship'], $this->remainingNames($client));

        $client->submit($confirmation->filter('[data-confirm-delete] button')->form());
        $client->followRedirect();
        $this->deliver($client);

        self::assertSame(['order.reserve'], $this->remainingNames($client));
        self::assertSame([], $this->work->handled);
    }

    public function test_only_this_failure_narrows_the_list_to_that_failure(): void
    {
        $client = $this->pulseWithSharedException();
        $list = $this->freshView($client, '/dead-letters');

        $narrowed = $client->click($list->filter('[data-group][data-message-name="order.reserve"] [data-only-failure]')->link());

        self::assertCount(1, $narrowed->filter('[data-group]'));
        self::assertSame('order.reserve', $narrowed->filter('[data-group]')->attr('data-message-name'));
        self::assertSame($narrowed->filter('[data-group]')->attr('data-failure'), $narrowed->filter('#f-failure')->attr('value'));
    }

    public function test_the_failure_filter_works_next_to_the_message_name_and_exception_class_filters(): void
    {
        $client = $this->pulseWithSharedException();
        $list = $this->freshView($client, '/dead-letters');
        $shipFailure = $list->filter('[data-group][data-message-name="order.ship"]')->attr('data-failure');

        self::assertCount(2, $client->request('GET', '/dead-letters', ['exceptionClass' => 'RuntimeException'])->filter('[data-group]'));
        self::assertCount(1, $client->request('GET', '/dead-letters', ['messageName' => 'order.reserve'])->filter('[data-group]'));
        self::assertCount(0, $client->request('GET', '/dead-letters', ['failureId' => $shipFailure, 'messageName' => 'order.reserve'])->filter('[data-group]'));
    }

    public function test_the_detail_shows_the_failure_and_replays_every_dead_letter_with_it(): void
    {
        $client = $this->pulseWithSharedException();
        $list = $this->freshView($client, '/dead-letters');
        $detail = $client->click($list->filter('[data-dead-letter][data-message-name="order.ship"] a')->link());
        $this->work->failing = false;

        self::assertSame('RuntimeException', $detail->filter('[data-detail="failureClass"]')->text());
        self::assertMatchesRegularExpression('/^DeadLetterServicesTrait\.php:\d+$/', $detail->filter('[data-detail="failureLocation"]')->text());
        self::assertSame('1 more with this failure', trim($detail->filter('[data-similar]')->text()));
        self::assertSame('Replay all 2 with this failure', trim($detail->filter('[data-failure-actions] button[name="failure"]')->text()));

        $client->submit($detail->filter('[data-failure-actions] button[name="failure"]')->form());
        $this->deliver($client);
        $this->replayWorkOf($this->services['orders']);

        self::assertEqualsCanonicalizing(['order.ship:1', 'order.ship:2'], $this->work->handled);
        self::assertSame(['order.reserve'], $this->remainingNames($client));
    }

    public function test_the_navigation_the_services_table_and_the_hub_count_failures(): void
    {
        $client = $this->pulseWithSharedException();
        $this->freshView($client, '/dead-letters');

        $services = $client->request('GET', '/');
        self::assertSame('2', $services->filter('[data-summary="dead-letters"] b')->text());
        self::assertStringContainsString('failures', $services->filter('[data-summary="dead-letters"] span')->text());
        self::assertSame('2', $services->filter('tr[data-service="orders"] [data-dead-letters]')->text());
        self::assertSame('2 failures', $services->filter('nav [data-nav-dead-letters]')->text());

        $hub = $client->request('GET', '/services/orders');
        self::assertSame('2', $hub->filter('[data-hub-dead-letters] [data-total]')->text());
        self::assertStringContainsString('2 failures (3 stored)', preg_replace('/\s+/', ' ', $hub->filter('[data-hub-dead-letters]')->text()));
    }

    private function pulseWithSharedException(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceWithDeadLetters('orders');
        $this->failOrder($orders, 'order.ship', 1);
        $this->failOrder($orders, 'order.reserve', 3);
        $this->failOrder($orders, 'order.ship', 2);

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
    private function remainingNames(KernelBrowser $client): array
    {
        $names = $client->request('GET', '/dead-letters')->filter('[data-dead-letter]')->each(static fn (Crawler $row) => $row->attr('data-message-name'));
        sort($names);

        return $names;
    }
}
