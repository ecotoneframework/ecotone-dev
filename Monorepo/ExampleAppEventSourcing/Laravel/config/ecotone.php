<?php

return [
    'namespaces' => ['Monorepo\\ExampleAppEventSourcing\\Common\\', 'Monorepo\\ExampleAppEventSourcing\\EcotoneProjection\\'],
    'enabledModulePackageNames' => \json_decode(\getenv('APP_ENABLED_MODULE_PACKAGE_NAMES'), true) ?? [],
];
