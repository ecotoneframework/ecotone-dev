<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Execution;

use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CoroutineMessageChannelRefusedTest extends TestCase
{
    public function test_channel_is_refused_without_an_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Coroutine channel `async` requires an Ecotone Enterprise licence. Pass a licence key when bootstrapping Ecotone, or register another message channel builder under `async`.');

        EcotoneLite::bootstrapFlowTesting(extensionObjects: [CoroutineMessageChannelBuilder::create('async')]);
    }
    public function test_channel_is_refused_without_a_coroutine_extension(): void
    {
        if (extension_loaded('swoole') || extension_loaded('openswoole')) {
            $this->markTestSkipped();
        }
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine channel `async` requires the swoole or openswoole extension. Install either extension for the PHP running the application, or register another message channel builder under `async`.');

        EcotoneLite::bootstrapFlowTesting(extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
    }

}
