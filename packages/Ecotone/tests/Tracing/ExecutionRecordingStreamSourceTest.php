<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use DateTimeImmutable;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\StreamSource as StreamSourceAttribute;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Ecotone\Test\LicenceTesting;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingStreamSourceTest extends TracingTestCase
{
    public function test_a_stream_source_of_the_application_naming_its_parameters_its_own_way_is_loaded_and_recorded(): void
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream')] class () {
            public array $handled = [];

            #[EventHandler('*')]
            public function handle(array $event): void
            {
                $this->handled[] = $event;
            }
        };
        $streamSource = new #[StreamSourceAttribute] class () implements StreamSource {
            public function canHandle(string $projectionName): bool
            {
                return true;
            }

            public function load(string $name, ?string $from, int $limit, ?string $partition = null): StreamPage
            {
                return $from === null
                    ? new StreamPage([Event::createWithType('order-placed', ['orderId' => '1'])], '1')
                    : new StreamPage([], $from);
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$projection::class, $streamSource::class],
            [$projection, $streamSource],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-11-22 10:00:00'),
        );

        $ecotone->publishEventWithRouting('trigger', []);

        self::assertSame([['orderId' => '1']], $projection->handled);
        self::assertContains('load events of orders', array_map(fn (string $line): string => trim($line), $ecotone->popExecutionRecording()->lines));
    }
}
