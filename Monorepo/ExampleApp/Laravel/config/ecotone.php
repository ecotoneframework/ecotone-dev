<?php

use Ecotone\Api\ExtensionObject\ModulePackageList;

return [
    'namespaces' => ['Monorepo\\ExampleApp\\Common\\'],
    'enabledModulePackageNames' => \json_decode(\getenv('APP_ENABLED_MODULE_PACKAGE_NAMES'), true) ?? [],
];
