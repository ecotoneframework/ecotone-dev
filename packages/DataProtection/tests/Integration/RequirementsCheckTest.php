<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Integration;

use Ecotone\Api\DataProtection\DataProtectionConfiguration;
use Ecotone\Api\DataProtection\Key;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
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

        EcotoneLite::bootstrapFlowTesting(extensionObjects: [DataProtectionConfiguration::create('primary', Key::createNewRandomKey())], enabledModules: [ModulePackageList::DATA_PROTECTION_PACKAGE]);
    }

    public function test_using_sensitive_attribute_on_both_class_and_property_throws_an_exception(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [AnnotatedClassWithAnnotatedProperty::class],
            extensionObjects: [DataProtectionConfiguration::create('primary', Key::createNewRandomKey())],
            enabledModules: [ModulePackageList::DATA_PROTECTION_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
