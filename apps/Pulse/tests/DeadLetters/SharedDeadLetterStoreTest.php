<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
final class SharedDeadLetterStoreTest extends WebTestCase
{
    use DeadLetterServicesTrait;

    public function test_services_reporting_the_same_dead_letters_are_named_in_a_warning(): void
    {
        $client = $this->twoServicesOnOneDatabase();

        $list = $this->freshView($client);

        $warning = $list->filter('[data-shared-store]')->text();
        self::assertStringContainsString('orders', $warning);
        self::assertStringContainsString('invoices', $warning);
        self::assertStringContainsString('share one dead letter store', $warning);
        self::assertStringContainsString('act through one of them', $warning);
    }

    public function test_services_with_their_own_dead_letters_raise_no_warning(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->failOrder($this->serviceWithDeadLetters('orders'), 'order.place', 1);
        $this->serviceWithoutDeadLetters('billing');

        $list = $this->freshView($client);

        self::assertCount(0, $list->filter('[data-shared-store]'));
    }

    public function test_a_filter_action_across_services_is_sent_to_one_service_of_those_sharing_a_store(): void
    {
        $client = $this->twoServicesOnOneDatabase();
        $this->freshView($client);
        $this->advanceTimeBy(1);
        $this->works['orders']->failing = false;
        $this->works['invoices']->failing = false;

        $client->request('POST', '/dead-letters/actions', ['action' => 'replay', 'scope' => 'filter', 'messageName' => 'order.place']);
        $waiting = $client->followRedirect();

        self::assertStringContainsString('waiting', $waiting->filter('[data-service-section="orders"]')->text());
        self::assertStringNotContainsString('waiting', $waiting->filter('[data-service-section="invoices"]')->text());
        $this->runAllServices();
        $this->replayWorkOf($this->services['orders']);
        $this->replayWorkOf($this->services['invoices']);
        self::assertSame(1, count($this->works['orders']->handled) + count($this->works['invoices']->handled));
    }

    private function twoServicesOnOneDatabase(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'invoices');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->serviceWithDeadLetters('orders');
        $this->serviceWithDeadLetters('invoices');
        $this->failOrder($this->services['orders'], 'order.place', 1);

        return $client;
    }

    private function freshView(KernelBrowser $client): \Symfony\Component\DomCrawler\Crawler
    {
        $client->request('POST', '/refresh');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', '/dead-letters');
    }
}
