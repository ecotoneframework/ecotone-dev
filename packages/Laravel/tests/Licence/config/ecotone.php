<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([
        ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::ASYNCHRONOUS_PACKAGE,
    ]),
    'licenceKey' => Ecotone\Test\LicenceTesting::VALID_LICENCE,
];
