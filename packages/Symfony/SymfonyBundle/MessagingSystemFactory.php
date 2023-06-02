<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\PreparedConfiguration;
use Ecotone\Messaging\Handler\ReferenceSearchService;

class MessagingSystemFactory
{
    public static function create(ReferenceSearchService $referenceSearchService, string $configurationFilename): ConfiguredMessagingSystem
    {
        /** @var PreparedConfiguration $preparedConfiguration */
        $preparedConfiguration = require $configurationFilename;
        $messagingSystem = $preparedConfiguration->buildMessagingSystemFromConfiguration($referenceSearchService);
        $referenceSearchService->setConfiguredMessagingSystem($messagingSystem);

        return $messagingSystem;
    }
}
