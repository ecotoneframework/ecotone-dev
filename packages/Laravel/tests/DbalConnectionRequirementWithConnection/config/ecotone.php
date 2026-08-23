<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'namespaces' => [],
    'modulePackages' => [ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::DBAL_PACKAGE, ],
    'test' => false,
];
