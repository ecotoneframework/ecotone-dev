<?php

return [
    'namespaces' => ['Monorepo\\ExampleAppEventSourcing\\Common\\'],
    'skippedModulePackageNames' => \json_decode(\getenv('APP_SKIPPED_PACKAGES'), true),
    'cacheConfiguration' => \getenv('APP_ENV') === 'prod',
    'defaultErrorChannel' => 'errorChannel',
];
