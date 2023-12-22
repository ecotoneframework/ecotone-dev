<?php

namespace App\MultiTenant;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\Parameter\ConfigurationVariable;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class Configuration
{
    /**
     * @param string[] $tenantDatabases
     */
    #[ServiceContext]
    public function channelConfig(#[ConfigurationVariable('tenant_connection_factories')] array $tenantDatabases): array
    {
        $channels = [];
        foreach ($tenantDatabases as $tenantName => $connectionFactoryName) {
            /** We are using Custom Connection Factory for each Tenant */
            $channels[$tenantName] = DbalBackedMessageChannelBuilder::create($tenantName, $connectionFactoryName);
        }

        return [
            DynamicMessageChannelBuilder::createRoundRobin("image_processing", array_keys($channels))
                /** We will take whatever is under tenant Message Header and use it to map to Interna Channel */
                ->withHeaderSendingStrategy('tenant')
                /** We are making this Channels internal only to be used within Dynamic Message Channel */
                ->withInternalChannels($channels),
        ];
    }

    #[ServiceContext]
    public function consumerDefinition(): PollingMetadata
    {
        return PollingMetadata::create('image_processing')
                    ->setHandledMessageLimit(1)
                    ->setExecutionAmountLimit(1)
                    ->setStopOnError(true);
    }
}