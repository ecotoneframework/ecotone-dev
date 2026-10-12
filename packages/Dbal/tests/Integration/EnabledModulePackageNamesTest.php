<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class EnabledModulePackageNamesTest extends TestCase
{
    public function test_a_listed_module_package_is_loaded(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE]),
        );

        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_an_unlisted_module_package_is_not_loaded(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([]),
        );

        $this->expectException(InvalidArgumentException::class);

        $ecotone->getGateway(DeadLetterGateway::class);
    }
}
