<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 *
 * SKIPPED: EventStreamEmitter feature is tightly coupled with the old Prooph-based projection system.
 * The new GlobalProjection system does not yet have a direct equivalent for emitting events from projections.
 * The original tests use LazyProophProjectionManager::getProjectionStreamName() which is Prooph-specific.
 *
 * Original tests covered:
 * - test_projection_emitting_events
 * - test_when_projection_is_deleted_emitted_events_will_be_removed_too
 * - test_projection_emitting_events_should_not_republished_in_case_replaying_projection
 */
final class EmittingEventsProjectionTest extends TestCase
{
    public function test_skipped_event_stream_emitter_not_supported_in_new_projecting_system(): void
    {
        $this->markTestSkipped(
            'EventStreamEmitter feature is tightly coupled with the old Prooph-based projection system. ' .
            'The new GlobalProjection system does not yet have a direct equivalent for emitting events from projections.'
        );
    }
}

