<?php

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;

return ServiceConfiguration::createWithAsynchronicityOnly()
        ->withSkippedModulePackageNames(
            ModulePackageList::allPackagesExcept([
                ModulePackageList::ASYNCHRONOUS_PACKAGE
            ])
        );
