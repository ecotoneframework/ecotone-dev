<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\Brokers;
use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;

/**
 * @internal
 */
final class KafkaTransportTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_a_service_whose_topic_does_not_exist_is_shown_as_channel_missing_naming_the_destination(): void
    {
        $suffix = uniqid();
        $this->pulseChannel = 'pulse_' . $suffix;
        $this->serviceChannels = ['orders' => 'orders_' . $suffix];
        $this->configurePulse(['PULSE_CHANNEL' => $this->pulseChannel, 'PULSE_TRANSPORT_DSN' => Brokers::kafkaDsn(), 'PULSE_SERVICES' => 'orders:' . $this->serviceChannels['orders']]);
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseInternalStorage($client->getKernel());

        $crawler = $client->request('GET', '/services');

        self::assertResponseIsSuccessful();
        self::assertSame('channel_missing', $crawler->filter('[data-service="orders"] [data-status]')->attr('data-status'));
        self::assertStringContainsString(sprintf("'%s' does not exist", $this->serviceChannels['orders']), $crawler->filter('[data-service="orders"] [data-request-error]')->text());
    }
}
