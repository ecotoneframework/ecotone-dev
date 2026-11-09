<?php

declare(strict_types=1);

namespace App\Tests\Connect;

use App\Tests\Support\ProblemBoardServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ConnectGuidePageTest extends ReleasingWebTestCase
{
    use ProblemBoardServicesTrait;

    public function test_the_framework_choice_comes_first_and_the_steps_follow_in_order(): void
    {
        $client = $this->pulseWith('orders');

        $crawler = $client->request('GET', '/connect');

        $html = (string) $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'data-connect-steps'), strpos($html, 'data-framework-tabs'));
        self::assertLessThan(strpos($html, 'data-framework-tabs'), strpos($html, 'data-verdict='));
        self::assertSame(['Symfony', 'Laravel', 'Ecotone Lite / PSR-11', 'Tempest'], $crawler->filter('[data-framework-tab]')->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertSame(['needs', 'configure', 'pulse-services', 'verify'], $crawler->filter('[data-connect-steps] > li')->each(static fn (Crawler $step): string => (string) $step->attr('data-step')));
    }

    public function test_the_last_step_says_the_checks_pulse_ran_appear_on_the_overview_of_a_service_that_does_not_answer(): void
    {
        $client = $this->pulseWith('orders');

        $crawler = $client->request('GET', '/connect');

        $verify = $crawler->filter('[data-step="verify"]')->text();
        self::assertStringContainsString('its Overview shows a Diagnose section with what Pulse checked', $verify);
        self::assertStringNotContainsString('lists under Diagnose', $verify);
    }

    public function test_the_chosen_framework_snippet_is_open_and_listed_first_and_the_others_are_collapsed(): void
    {
        $client = $this->pulseWith('orders');

        $crawler = $client->request('GET', '/connect?service=orders&framework=laravel');

        self::assertSame('page', $crawler->filter('[data-framework-tab="laravel"]')->attr('aria-current'));
        self::assertSame('/connect?service=orders&framework=tempest', $crawler->filter('[data-framework-tab="tempest"]')->attr('href'));
        self::assertSame('laravel', $crawler->filter('[data-step="configure"] [data-snippet]')->first()->attr('data-snippet'));
        self::assertSame(['laravel'], $crawler->filter('[data-snippet][open]')->each(static fn (Crawler $snippet): string => (string) $snippet->attr('data-snippet')));
        self::assertSame('laravel', $crawler->filter('form.connect-form input[name="framework"]')->attr('value'));
        self::assertCount(0, $crawler->filter('details details'));
    }

    public function test_without_a_choice_the_page_starts_with_symfony(): void
    {
        $client = $this->pulseWith('orders');

        $crawler = $client->request('GET', '/connect?framework=unknown');

        self::assertSame(['symfony'], $crawler->filter('[data-snippet][open]')->each(static fn (Crawler $snippet): string => (string) $snippet->attr('data-snippet')));
    }

    public function test_the_framework_a_connected_service_reports_is_chosen_for_it(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->framework = 'tempest';

        $crawler = $this->observeBoard($client, '/connect?service=orders');

        self::assertSame(['tempest'], $crawler->filter('[data-snippet][open]')->each(static fn (Crawler $snippet): string => (string) $snippet->attr('data-snippet')));
    }

    public function test_every_snippet_and_the_services_entry_have_a_copy_button(): void
    {
        $client = $this->pulseWith('orders');

        $crawler = $client->request('GET', '/connect?service=orders');

        $blocks = $crawler->filter('[data-connect-steps] pre');
        self::assertGreaterThan(8, $blocks->count());
        foreach ($blocks as $block) {
            $id = (string) $block->getAttribute('id');
            self::assertNotSame('', $id);
            self::assertCount(1, $crawler->filter('button[data-copy="#' . $id . '"]'), $id);
        }
        self::assertSame('PULSE_SERVICES=orders:' . $this->serviceChannels['orders'], $crawler->filter('[data-step="pulse-services"] [data-pulse-services]')->text());
    }

    public function test_the_status_line_says_which_configured_services_answer(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $crawler = $this->observeBoard($client, '/connect');

        self::assertSame('ok', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('orders answers Pulse. To connect another service, follow the steps below.', trim($crawler->filter('[data-verdict-sentence]')->text()));
        self::assertSame('/services/orders', $crawler->filter('[data-connect-answering] a')->attr('href'));
    }

    public function test_a_configured_service_that_does_not_answer_is_critical_and_offers_its_diagnosis(): void
    {
        $client = $this->pulseWith('orders');
        $client->request('GET', '/');
        $this->advanceTimeBy(10);

        $crawler = $client->request('GET', '/connect');

        self::assertSame('critical', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertStringContainsString('0 of 1 configured services answer Pulse', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertSame('Diagnose orders', $crawler->filter('[data-verdict-action]')->text());
        self::assertSame('/services/orders#diagnose', $crawler->filter('[data-verdict-action]')->attr('href'));
        self::assertSame('/services/orders', $crawler->filter('[data-connect-not-answering] a')->attr('href'));
    }

    public function test_a_service_not_in_pulse_services_gets_a_focused_flow_to_connect_it(): void
    {
        $client = $this->pulseWith('orders');

        $crawler = $client->request('GET', '/connect?service=shipping');

        self::assertSame('Connect shipping', trim($crawler->filter('h1')->text()));
        self::assertSame(['Home', 'Connect a service', 'shipping'], $crawler->filter('[data-crumbs] li')->each(static fn (Crawler $crumb): string => trim($crumb->text())));
        self::assertSame('info', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('shipping is not in PULSE_SERVICES yet: the 4 steps below connect it.', trim($crawler->filter('[data-verdict-sentence]')->text()));
        self::assertStringContainsString("withServiceName('shipping')", $crawler->filter('[data-snippet="symfony"] [data-php]')->text(null, false));
        self::assertStringContainsString('restart Pulse', $crawler->filter('[data-step="pulse-services"]')->text());
    }

    public function test_a_connected_service_is_told_nothing_is_left_to_do(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $crawler = $this->observeBoard($client, '/connect?service=orders');

        self::assertSame('ok', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertStringStartsWith('orders is connected: it answered Pulse', trim($crawler->filter('[data-verdict-sentence]')->text()));
        self::assertSame('/services/orders', $crawler->filter('[data-verdict-action]')->attr('href'));
        self::assertStringContainsString('already in PULSE_SERVICES', $crawler->filter('[data-step="pulse-services"]')->text());
    }

    public function test_each_term_a_beginner_meets_is_explained_where_it_first_appears(): void
    {
        $client = $this->pulseWith('orders');

        $crawler = $client->request('GET', '/connect?service=orders');

        foreach (['licence-key', 'service-name', 'snapshot', 'distributed-bus', 'request-channel', 'pulse-services'] as $term) {
            self::assertGreaterThan(0, $crawler->filter('[data-connect-steps] [data-concept="' . $term . '"]')->count(), $term);
        }
    }

    public function test_the_service_hub_still_shows_the_snippets_with_copy_buttons_when_a_service_does_not_answer(): void
    {
        $client = $this->pulseWith('orders');
        $client->request('GET', '/services/orders');
        $this->advanceTimeBy(10);

        $crawler = $client->request('GET', '/services/orders');

        self::assertCount(4, $crawler->filter('[data-diagnose-section] [data-snippet]'));
        self::assertCount(1, $crawler->filter('[data-diagnose-section] [data-snippet][open]'));
        self::assertCount(1, $crawler->filter('[data-diagnose-section] button[data-copy="#snippet-symfony-php"]'));
    }

    private function pulseWith(string $service): KernelBrowser
    {
        $this->configurePulseWithServices($service);
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }
}
