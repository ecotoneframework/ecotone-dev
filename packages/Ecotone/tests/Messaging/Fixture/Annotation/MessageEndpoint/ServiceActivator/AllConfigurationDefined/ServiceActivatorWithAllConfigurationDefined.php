<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\ServiceActivator\AllConfigurationDefined;

use Ecotone\Api\ConfigurationVariable;
use Ecotone\Api\Header;
use Ecotone\Api\Payload;
use Ecotone\Api\Reference;
use Ecotone\Api\ServiceActivator;
use Ecotone\Messaging\Message;
use stdClass;

/**
 * licence Apache-2.0
 */
class ServiceActivatorWithAllConfigurationDefined
{
    #[ServiceActivator(
        endpointId: 'test-name',
        inputChannelName: 'inputChannel',
        outputChannelName: 'outputChannel',
        requiresReply: true,
        requiredInterceptorNames: ['someReference']
    )]
    public function sendMessage(#[Header('sendTo')] string $to, #[Payload] string $content, Message $message, #[Reference] stdClass $object, #[Header('token', 'value')] ?string $name, #[ConfigurationVariable('env')] string $environment): void
    {
    }
}
