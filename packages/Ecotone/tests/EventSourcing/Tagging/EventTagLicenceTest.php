<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Support\LicensingException;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class EventTagLicenceTest extends TestCase
{
    public function test_bootstrap_throws_licensing_exception_when_event_tag_used_without_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [TaggedEventForLicenceTest::class],
        );
    }
}

final readonly class TaggedEventForLicenceTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}
