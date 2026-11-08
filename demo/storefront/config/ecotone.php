<?php

return [
    'licenceKey' => env('ECOTONE_LICENCE_KEY'),
    'loadAppNamespaces' => true,
    'namespaces' => [],
    'enabledModulePackageNames' => null,
    'test' => env('APP_ENV') === 'testing',
];
