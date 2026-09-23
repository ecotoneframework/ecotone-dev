<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class EventTagResolutionTest extends TestCase
{
    public function test_plain_property_is_resolved_as_a_tag(): void
    {
        $taggedEventStore = $this->bootstrapTaggedEventStore([EventTaggedOnPlainProperty::class]);

        $taggedEventStore->appendTo('ecotone_event_stream', [new EventTaggedOnPlainProperty('course-1')]);

        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('course', 'course-1'))->events);
    }

    public function test_method_computed_value_is_resolved_as_a_tag(): void
    {
        $taggedEventStore = $this->bootstrapTaggedEventStore([EventTaggedOnMethod::class]);

        $taggedEventStore->appendTo('ecotone_event_stream', [new EventTaggedOnMethod('summer24')]);

        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('customerEmail', 'SUMMER24-HASH'))->events);
    }

    public function test_class_level_literal_value_is_resolved_as_a_tag(): void
    {
        $taggedEventStore = $this->bootstrapTaggedEventStore([EventTaggedOnClass::class]);

        $taggedEventStore->appendTo('ecotone_event_stream', [new EventTaggedOnClass(1)]);

        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('invoiceSequence', 'default'))->events);
    }

    public function test_repeated_key_on_different_properties_produces_two_tag_rows(): void
    {
        $taggedEventStore = $this->bootstrapTaggedEventStore([MoneyTransferredForResolutionTest::class]);

        $taggedEventStore->appendTo('ecotone_event_stream', [new MoneyTransferredForResolutionTest('acc-1', 'acc-2')]);

        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('account', 'acc-1'))->events);
        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('account', 'acc-2'))->events);
    }

    public function test_array_value_produces_one_tag_row_per_element(): void
    {
        $taggedEventStore = $this->bootstrapTaggedEventStore([SeatsReservedForResolutionTest::class]);

        $taggedEventStore->appendTo('ecotone_event_stream', [new SeatsReservedForResolutionTest(['seat-1', 'seat-2'])]);

        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('seat', 'seat-1'))->events);
        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('seat', 'seat-2'))->events);
    }

    public function test_null_value_is_skipped_and_produces_no_tag_row(): void
    {
        $taggedEventStore = $this->bootstrapTaggedEventStore([OrderPlacedForResolutionTest::class]);

        $taggedEventStore->appendTo('ecotone_event_stream', [new OrderPlacedForResolutionTest('customer-1', null)]);

        $this->assertCount(0, $taggedEventStore->load(EventCriteria::tag('coupon', ''))->events);
        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('customer', 'customer-1'))->events);
    }

    public function test_empty_tag_value_is_rejected(): void
    {
        $taggedEventStore = $this->bootstrapTaggedEventStore([EventTaggedOnPlainProperty::class]);

        $this->expectException(ConfigurationException::class);

        $taggedEventStore->appendTo('ecotone_event_stream', [new EventTaggedOnPlainProperty('')]);
    }

    public function test_too_long_tag_value_is_rejected(): void
    {
        $taggedEventStore = $this->bootstrapTaggedEventStore([EventTaggedOnPlainProperty::class]);

        $this->expectException(ConfigurationException::class);

        $taggedEventStore->appendTo('ecotone_event_stream', [new EventTaggedOnPlainProperty(str_repeat('a', 256))]);
    }

    public function test_trailing_whitespace_tag_value_is_rejected(): void
    {
        $taggedEventStore = $this->bootstrapTaggedEventStore([EventTaggedOnPlainProperty::class]);

        $this->expectException(ConfigurationException::class);

        $taggedEventStore->appendTo('ecotone_event_stream', [new EventTaggedOnPlainProperty('course-1 ')]);
    }

    public function test_non_scalar_typed_property_is_rejected_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->bootstrapTaggedEventStore([EventTaggedOnNonScalarProperty::class]);
    }

    /**
     * @param class-string[] $classesToResolve
     */
    private function bootstrapTaggedEventStore(array $classesToResolve): TaggedEventStore
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $ecotone->getServiceFromContainer(TaggedEventStore::class);
    }
}

final class EventTaggedOnPlainProperty
{
    #[EventTag('course')]
    public string $courseId;

    public function __construct(string $courseId)
    {
        $this->courseId = $courseId;
    }
}

final readonly class EventTaggedOnMethod
{
    public function __construct(
        public string $couponCode,
    ) {
    }

    #[EventTag('customerEmail')]
    public function customerEmailHash(): string
    {
        return strtoupper($this->couponCode) . '-HASH';
    }
}

#[EventTag('invoiceSequence', value: 'default')]
final readonly class EventTaggedOnClass
{
    public function __construct(
        public int $number,
    ) {
    }
}

final readonly class MoneyTransferredForResolutionTest
{
    public function __construct(
        #[EventTag('account')] public string $fromAccountId,
        #[EventTag('account')] public string $toAccountId,
    ) {
    }
}

final readonly class SeatsReservedForResolutionTest
{
    /**
     * @param string[] $seatIds
     */
    public function __construct(
        #[EventTag('seat')] public array $seatIds,
    ) {
    }
}

final readonly class OrderPlacedForResolutionTest
{
    public function __construct(
        #[EventTag('customer')] public string $customerId,
        #[EventTag('coupon')] public ?string $couponCode,
    ) {
    }
}

final readonly class EventTaggedOnNonScalarProperty
{
    public function __construct(
        #[EventTag('course')] public object $course,
    ) {
    }
}
