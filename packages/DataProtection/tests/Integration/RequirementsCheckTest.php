<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Integration;

use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\DataProtection\Fixture\AnnotatedClassWithAnnotatedProperty;

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

    public function test_using_sensitive_attribute_on_both_class_and_property_throws_an_exception(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [AnnotatedClassWithAnnotatedProperty::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DATA_PROTECTION_PACKAGE]))
                ->withExtensionObjects([
                    DataProtectionConfiguration::create('primary', Key::createNewRandomKey()),
                ])
        );
    }
}
