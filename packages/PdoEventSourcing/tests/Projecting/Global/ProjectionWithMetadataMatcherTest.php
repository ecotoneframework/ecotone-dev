<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 *
 * SKIPPED: MetadataMatcher is a Prooph-specific feature used with ProjectionRunningConfiguration::OPTION_METADATA_MATCHER.
 * The new GlobalProjection system doesn't have an equivalent configuration option for metadata matching at the stream source level.
 *
 * Original tests covered:
 * - test_configured_metadata_matcher_is_used_with_polling_projection
 * - test_configured_metadata_matcher_is_used_with_event_driven_projection
 */
final class ProjectionWithMetadataMatcherTest extends TestCase
{
    public function test_skipped_metadata_matcher_not_supported_in_new_system(): void
    {
        $this->markTestSkipped(
            'MetadataMatcher is a Prooph-specific feature used with ProjectionRunningConfiguration::OPTION_METADATA_MATCHER. ' .
            'The new GlobalProjection system does not have an equivalent configuration option for metadata matching.'
        );
    }
}

