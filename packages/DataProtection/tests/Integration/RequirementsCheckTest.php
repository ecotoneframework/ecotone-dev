<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Integration;

use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\LicensingException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class RequirementsCheckTest extends TestCase
{
    public function test_without_license_module_throws_an_exception(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DATA_PROTECTION_PACKAGE]))
                ->withExtensionObjects([
                    DataProtectionConfiguration::create('primary', Key::createNewRandomKey()),
                ])
        );
    }
}
