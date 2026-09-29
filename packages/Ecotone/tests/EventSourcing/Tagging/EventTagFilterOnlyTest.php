<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class EventTagFilterOnlyTest extends TestCase
{
    public function test_filter_only_tag_is_indexed_for_reads_but_never_causes_a_conflict(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [TenantScopedEventForFilterOnlyTest::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withFilterOnlyTags(['tenant'])]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getServiceFromContainer(EventStore::class);

        $loadedEvents = $eventStore->loadByCriteria(EventCriteria::tag('tenant', 'acme'));

        $eventStore->appendTo('ecotone_event_stream', [new TenantScopedEventForFilterOnlyTest('acme', 'x')]);
        $eventStore->appendTo('ecotone_event_stream', [new TenantScopedEventForFilterOnlyTest('acme', 'y')]);

        $eventStore->appendTo(
            'ecotone_event_stream',
            [new TenantScopedEventForFilterOnlyTest('acme', 'z')],
            $loadedEvents->appendCondition,
        );

        $this->assertCount(3, $eventStore->loadByCriteria(EventCriteria::tag('tenant', 'acme'))->events);
    }

    public function test_declaring_a_filter_only_tag_no_event_carries_is_rejected_at_bootstrap_naming_it_and_the_known_tags(): void
    {
        try {
            EcotoneLite::bootstrapFlowTesting(
                classesToResolve: [TenantScopedEventForFilterOnlyTest::class],
                configuration: ServiceConfiguration::createWithDefaults()
                    ->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withFilterOnlyTags(['tennant'])]),
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString("'tennant'", $exception->getMessage());
            $this->assertStringContainsString('tenant, itemId', $exception->getMessage());
        }
    }
}

final readonly class TenantScopedEventForFilterOnlyTest
{
    public function __construct(
        #[EventTag('tenant')] public string $tenantId,
        #[EventTag('itemId')] public string $itemId,
    ) {
    }
}
