<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;

/**
 * @internal
 */
final class DeadLetterNoticeTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_free_text_in_the_query_string_is_not_rendered_as_a_notice(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $this->setUpPulseInternalStorage($client->getKernel());

        $crawler = $client->request('GET', '/dead-letters?actionError=Your+session+expired,+call+support&notice=made_up&actionSent=hello');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-notice], [data-notice-error]'));
        self::assertStringNotContainsString('call support', $client->getResponse()->getContent());
    }

    public function test_a_notice_naming_a_service_that_is_not_configured_is_ignored(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $this->setUpPulseInternalStorage($client->getKernel());

        $crawler = $client->request('GET', '/dead-letters?notice=action_sent&action=delete&services=ghost');

        self::assertCount(0, $crawler->filter('[data-notice]'));
    }

    public function test_a_known_notice_is_rendered_from_its_fixed_text(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $this->setUpPulseInternalStorage($client->getKernel());

        $crawler = $client->request('GET', '/dead-letters?notice=action_sent&action=delete&services=orders');

        self::assertStringContainsString('Action sent: delete to orders', $crawler->filter('[data-notice]')->text());
    }

    public function test_the_date_filters_are_labelled_as_utc_with_second_precision(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $this->setUpPulseInternalStorage($client->getKernel());

        $crawler = $client->request('GET', '/dead-letters');

        self::assertStringContainsString('Failed from (UTC)', $crawler->filter('[data-filter-form]')->text());
        self::assertSame('1', $crawler->filter('[data-filter-form] input[name="failedFrom"]')->attr('step'));
        self::assertSame('1', $crawler->filter('[data-filter-form] input[name="failedTo"]')->attr('step'));
    }

    public function test_the_detail_of_a_dead_letter_without_stack_trace_or_payload_is_shown(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $entry = ['messageId' => 'm-1', 'messageName' => 'order.place', 'failedAt' => gmdate('c'), 'exceptionClass' => 'RuntimeException', 'exceptionMessage' => 'boom', 'stackTrace' => null, 'payload' => null, 'contentType' => null, 'channel' => null];
        $this->services['orders'] = $this->serviceAnsweringWith('orders', json_encode([
            'schemaVersion' => 1,
            'serviceName' => 'orders',
            'takenAt' => gmdate('c'),
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
            'deadLetters' => ['total' => 1, 'groups' => [], 'recent' => [$entry]],
        ], JSON_THROW_ON_ERROR));
        $client->request('GET', '/dead-letters');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());

        $client->request('GET', '/dead-letters/orders/m-1');

        self::assertResponseIsSuccessful();
    }

    public function test_an_invalid_date_filter_shows_an_error_instead_of_failing(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $this->setUpPulseInternalStorage($client->getKernel());

        $crawler = $client->request('GET', '/dead-letters?failedFrom=garbage');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Failed from', $crawler->filter('[data-notice-error]')->text());
        self::assertStringContainsString('not a valid UTC date', $crawler->filter('[data-notice-error]')->text());
    }

    public function test_an_action_with_an_invalid_date_is_refused_with_an_error_and_sends_nothing(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->serviceWithDeadLetters('orders');
        $client->request('POST', '/refresh');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());
        $this->advanceTimeBy(1);

        $client->request('POST', '/dead-letters/actions', ['action' => 'delete', 'scope' => 'filter', 'service' => 'orders', 'failedTo' => 'x', 'confirm' => '1']);
        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Failed to', $crawler->filter('[data-notice-error]')->text());
        self::assertStringNotContainsString('waiting', $crawler->filter('[data-service-section="orders"]')->text());
    }
}
