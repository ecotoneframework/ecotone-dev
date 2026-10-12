<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

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
 */
final class LicenceKeyTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\User\\'],
            enabledModulePackageNames: \Test\Ecotone\Tempest\TempestTestModulePackages::all(),
            test: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_booting_with_licence_key_does_not_prevent_system_from_functioning(): void
    {
        $this->assertInstanceOf(
            CommandBus::class,
            $this->container->get(CommandBus::class),
        );
    }
}
