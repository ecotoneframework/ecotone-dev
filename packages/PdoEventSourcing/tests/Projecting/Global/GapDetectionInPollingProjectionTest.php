<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 *
 * SKIPPED: Gap detection in the new ProjectionV2 system is handled differently.
 * The new system uses GapAwarePosition built into EventStoreGlobalStreamSource with maxGapOffset and gapTimeout parameters.
 * The old tests use ProjectionRunningConfiguration::OPTION_GAP_DETECTION with Prooph-specific GapDetection class.
 *
 * Gap detection for the new system is already tested in:
 * - packages/PdoEventSourcing/tests/Projecting/GapAwarePositionTest.php (unit tests)
 * - packages/PdoEventSourcing/tests/Projecting/GapAwarePositionIntegrationTest.php (integration tests)
 *
 * Original tests covered:
 * - test_detecting_gaps_without_detection_window
 * - test_detecting_gaps_with_detection_window
 * - test_detecting_gaps_without_gap_detection
 */
final class GapDetectionInPollingProjectionTest extends TestCase
{
    public function test_skipped_gap_detection_handled_differently_in_new_system(): void
    {
        $this->markTestSkipped(
            'Gap detection in the new ProjectionV2 system is handled differently. ' .
            'The new system uses GapAwarePosition built into EventStoreGlobalStreamSource. ' .
            'See GapAwarePositionTest.php and GapAwarePositionIntegrationTest.php for new gap detection tests.'
        );
    }
}

