<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 *
 * SKIPPED: The new ProjectionV2 system with FromStream attribute only supports a single stream.
 * The old Projection attribute supports multiple streams via fromStreams array parameter.
 * The new system's EventStoreGlobalStreamSourceBuilder only takes a single streamName.
 *
 * Original tests covered:
 * - test_handling_multiple_streams_for_projection
 */
final class ProjectionFromMultipleStreamsTest extends TestCase
{
    public function test_skipped_multiple_streams_not_supported_in_new_system(): void
    {
        $this->markTestSkipped(
            'The new ProjectionV2 system with FromStream attribute only supports a single stream. ' .
            'Multiple streams projection is not yet supported in the new projecting system.'
        );
    }
}
