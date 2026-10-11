<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 *
 * SKIPPED: The new Projection system with FromStream attribute only supports a single stream.
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
            'The new Projection system with FromStream attribute only supports a single stream. ' .
            'Multiple streams projection is not yet supported in the new projecting system.'
        );
    }
}
