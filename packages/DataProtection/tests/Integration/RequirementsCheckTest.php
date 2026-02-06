<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Integration;

use Defuse\Crypto\Key;
use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
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

    public function test_module_require_data_protection_configuration(): void
    {
        $this->expectExceptionObject(InvalidArgumentException::create('Ecotone\DataProtection\Configuration\DataProtectionConfiguration was not found.'));

        EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DATA_PROTECTION_PACKAGE]))
        );
    }

    public function test_module_require_jms_converter_configuration(): void
    {
        $this->expectExceptionObject(InvalidArgumentException::create('Ecotone\DataProtection\Configuration\DataProtectionConfiguration was not found.'));

        EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DATA_PROTECTION_PACKAGE]))
        );
    }
}
