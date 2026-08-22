<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest;

use Ecotone\Messaging\Config\ModulePackageList;

/**
 * licence Apache-2.0
 */
final class TempestTestModulePackages
{
    public static function all(): array
    {
        return [
            ModulePackageList::TEMPEST_PACKAGE,
            ModulePackageList::JMS_CONVERTER_PACKAGE,
        ];
    }
}
