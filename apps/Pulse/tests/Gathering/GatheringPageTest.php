<?php

declare(strict_types=1);

namespace App\Tests\Gathering;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Scheduling\TimeSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * licence Apache-2.0
 * @internal
 */
final class GatheringPageTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    private const ENTERPRISE_LICENCE = 'eyJzaWduYXR1cmUiOiJxRmtrSDdCQkxzdUlTRzExZmVXeHVKVTY3bGZScmFSTFBpb0VUaGdoZTAyYWRjajlVRkNWWXhvaGl4YXhoaEFlNzU5aExOT0R5NXo1T2N2eFppUGQyemRRblYrb1dNZjFwM2lqbFN4cUg1MDhVV1wvOG90TllkM28wMVRZRmdMSjU4RXJzYThLcUJib0Z1MmhaZW9lczQ3elM2VHR5TzFGV2pOeWxlbG1aRWNLUHFydFhzOTNcL1YxY0wwWVg4UFBOZDl6bVRPOWxyc3UzM3F1anBXVlB4eVRMTW4yK3lPc3Jxa2pJZDArSU1LSWl0dG1IZGlBcml6NnZIXC93RnFKTU5Od2JKWnFsUXRNa3pQc3pvRlFseVgyZnNPTG5CTWNCajA5S0VnNjhVcG14OVwvUnBHZXBNV2UzcThYeFZMa3laOEtXdndEN2JBZDZPSXVoejlxRThyamVQVVVsbUxybm0wd3ZFYjV6UVZEbU5OY21uZnNkMEYyazJhSldySzc5VXlEWVY3UFNvVkZiM0diV0VNN2p1STVFV2w5cTVaWXJiOGxFcmxTTmNpYmQwbCttMWkzUWk1TnZhU1RscHBTV1BGS0g4c2x5dDhKRzV4YXZqQlJFU3BJWGVDNE9VREdoZDRJNEhaaEpDXC9Wcm9Nb0FRNzBMaXRrUHcxWkdWQ1dqTURucHdNdWRyWThpMVQwak5MRytXYlpYckpvRHZxd0x4SENKdU9EWnhuYVNISkVVSVFndGViUXZPRURIKzBjN0o0VVJMWkV2NnU2d2tcL1F6TzlPVnVVS1BubDkzK1dVam8xVDZqOEJ0MEtSeHhsMmtBY2pQcE9wWTg5Q25BQlBtckF4THJhYkxXeThpZmxVQ0cwUjNmZzB4UnpiMjlMSENvUW95b2NmRStzYjhFYz0iLCJkYXRhIjp7ImVtYWlsIjoiam9obnlAd3AucGwiLCJleHBpcmVBdCI6IjIwMzItMTAtMTEgMDA6MDA6MDAiLCJpc0VudGVycHJpc2VQbHVzIjpmYWxzZX19';

    private FlowTestSupport $orders;
    private object $handler;

    public function test_page_waits_for_the_first_snapshot_before_reporting_groups(): void
    {
        $client = $this->pulseWithGathering();

        $crawler = $client->request('GET', '/services/orders/gathering');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Waiting for the first answer of orders', $crawler->text());
        self::assertStringNotContainsString('No gathering groups reported.', $crawler->text());
    }

    public function test_page_distinguishes_a_service_that_does_not_report_gathering(): void
    {
        $client = $this->pulseWithGathering();
        $this->orders = $this->realService('orders');

        $crawler = $this->observe($client);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('orders does not report gathering.', $crawler->text());
        self::assertStringNotContainsString('No gathering groups reported.', $crawler->text());
    }

    public function test_page_shows_open_group_progress_and_operations(): void
    {
        $client = $this->pulseWithGathering();
        $this->orders->sendCommandWithRouting('split', [10, 20]);
        $groupId = $this->orders->getGateway(GatheredGroups::class)->listOpenGroups('summary')[0]['groupId'];

        $crawler = $this->observe($client);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-gathering-group="' . $groupId . '"]');
        self::assertCount(1, $row);
        self::assertSame('summary', $row->filter('[data-gather-channel]')->text());
        self::assertSame('open', $row->filter('[data-group-state]')->text());
        self::assertSame('1, 2', $row->filter('[data-missing-parts]')->text());
        self::assertCount(1, $row->filter('button[data-release-group]'));
        self::assertCount(1, $row->filter('button[data-discard-group]'));
        self::assertSame('page', $crawler->filter('[data-service-tab="gathering"]')->attr('aria-current'));
    }

    public function test_release_control_requests_release_before_handler_consumption(): void
    {
        $client = $this->pulseWithGathering();
        $this->orders->sendCommandWithRouting('split', [10, 20]);
        $groupId = $this->orders->getGateway(GatheredGroups::class)->listOpenGroups('summary')[0]['groupId'];
        $crawler = $this->observe($client);

        $client->submit($crawler->filter('[data-gathering-group="' . $groupId . '"]')->selectButton('Request release')->form());
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Release requested', $crawler->filter('[data-gathering-notice]')->text());
        self::assertSame([], $this->handler->gathered);
        $this->runService($this->orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        self::assertSame([], $this->handler->gathered);
        $this->orders->run('summaries');
        self::assertCount(1, $this->handler->gathered);
        self::assertSame([1, 2], $this->handler->gathered[0]->missingPartNumbers);
        $this->orders->run('workers');
        $this->orders->run('summaries');
        $crawler = $this->observe($client);
        $row = $crawler->filter('[data-gathering-group="' . $groupId . '"]');

        self::assertSame('released', $row->filter('[data-group-state]')->text());
        self::assertSame('2', $row->filter('[data-late-parts]')->text());
        self::assertCount(0, $row->filter('button'));
    }

    public function test_discard_control_closes_group_without_running_handler(): void
    {
        $client = $this->pulseWithGathering();
        $this->orders->sendCommandWithRouting('split', [10, 20]);
        $groupId = $this->orders->getGateway(GatheredGroups::class)->listOpenGroups('summary')[0]['groupId'];
        $crawler = $this->observe($client);

        $client->submit($crawler->filter('[data-gathering-group="' . $groupId . '"]')->selectButton('Discard')->form());
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Discard requested', $crawler->filter('[data-gathering-notice]')->text());
        self::assertCount(1, $this->orders->getGateway(GatheredGroups::class)->listOpenGroups('summary'));
        $this->runService($this->orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->orders->run('workers');
        $this->orders->run('summaries');
        $crawler = $this->observe($client);
        $row = $crawler->filter('[data-gathering-group="' . $groupId . '"]');

        self::assertSame([], $this->handler->gathered);
        self::assertSame('discarded', $row->filter('[data-group-state]')->text());
        self::assertSame('2', $row->filter('[data-late-parts]')->text());
        self::assertCount(0, $row->filter('button'));
    }

    public static function actions(): iterable
    {
        yield 'release' => ['Request release'];
        yield 'discard' => ['Discard'];
    }

    #[DataProvider('actions')]
    public function test_page_displays_service_refusal_for_a_cleaned_up_group(string $button): void
    {
        $client = $this->pulseWithGathering();
        $this->orders->sendCommandWithRouting('split', [10]);
        $groups = $this->orders->getGateway(GatheredGroups::class);
        $groupId = $groups->listOpenGroups('summary')[0]['groupId'];
        $crawler = $this->observe($client);
        $groups->discard($groupId);
        $this->orders->advanceTimeBy(new TimeSpan(days: 7));
        $groups->cleanupClosedGroups();

        $client->submit($crawler->filter('[data-gathering-group="' . $groupId . '"]')->selectButton($button)->form());
        self::assertResponseRedirects();
        $this->runService($this->orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $crawler = $client->followRedirect();

        self::assertCount(1, $crawler->filter('[data-gathering-error]'));
        self::assertStringContainsString('GatheringConfiguration::withRetention(...)', $crawler->filter('[data-gathering-error]')->text());
        self::assertSame([], $this->handler->gathered);
    }

    private function pulseWithGathering(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->pinnedWaitClock();
        $this->handler = $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'dashboard.split', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'dashboard.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'dashboard.summary')]
            public function summary(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };
        $this->orders = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory($_SERVER['PULSE_TRANSPORT_DSN'])],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create($this->serviceChannels['orders'])->withReceiveTimeout(1),
                DbalBackedMessageChannelBuilder::create($this->pulseChannel),
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: $this->pulseChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName('orders'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-10-20 12:00:00 UTC'),
        );

        return $client;
    }

    private function observe(KernelBrowser $client): Crawler
    {
        $client->request('POST', '/refresh');
        $this->runService($this->orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', '/services/orders/gathering');
    }
}
