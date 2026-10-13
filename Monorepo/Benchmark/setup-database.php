<?php

declare(strict_types=1);

use Ecotone\Api\ExtensionObject\ModulePackageList;

require __DIR__ . '/../../vendor/autoload.php';

putenv(sprintf(
    'APP_ENABLED_MODULE_PACKAGE_NAMES=%s',
    json_encode(
        [
            ModulePackageList::EVENT_SOURCING_PACKAGE,
            ModulePackageList::DBAL_PACKAGE,
            ModulePackageList::JMS_CONVERTER_PACKAGE,
        ],
        JSON_THROW_ON_ERROR
    )
));

$bootstrap = require __DIR__ . '/../ExampleAppEventSourcing/Lite/app.php';
$bootstrap(false)->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);

echo "Benchmark database tables are set up.\n";
