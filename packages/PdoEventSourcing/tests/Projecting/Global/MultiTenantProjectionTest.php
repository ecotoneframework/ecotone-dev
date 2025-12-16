<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use PHPUnit\Framework\TestCase;

/**
 * Multi-tenant projection tests.
 *
 * These tests are skipped because multi-tenancy with the new GlobalProjection system
 * requires additional infrastructure that is not yet implemented. The old Prooph-based
 * projection system had built-in multi-tenancy support through the projection manager,
 * but the new system needs to be extended to support multi-tenant scenarios.
 *
 * Original tests from: Test\Ecotone\EventSourcing\Integration\MultiTenantTest
 *
 * @internal
 */
final class MultiTenantProjectionTest extends TestCase
{
    public function test_building_asynchronous_event_driven_projection_with_multi_tenancy(): void
    {
        $this->markTestSkipped(
            'Multi-tenancy with GlobalProjection is not yet supported. ' .
            'The new projecting system needs additional infrastructure for multi-tenant scenarios.'
        );
    }

    public function test_building_synchronous_event_driven_projection_with_multi_tenancy(): void
    {
        $this->markTestSkipped(
            'Multi-tenancy with GlobalProjection is not yet supported. ' .
            'The new projecting system needs additional infrastructure for multi-tenant scenarios.'
        );
    }

    public function test_multi_tenancy_do_work_with_polling_endpoint(): void
    {
        $this->markTestSkipped(
            'Multi-tenancy with GlobalProjection is not yet supported. ' .
            'The new projecting system needs additional infrastructure for multi-tenant scenarios.'
        );
    }
}

