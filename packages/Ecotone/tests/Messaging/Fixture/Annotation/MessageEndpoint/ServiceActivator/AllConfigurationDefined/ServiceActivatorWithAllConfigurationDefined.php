<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\ServiceActivator\AllConfigurationDefined;

use Ecotone\Api\Attribute\Parameter\ConfigurationVariable;
use Ecotone\Api\Attribute\Parameter\Header;
use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Api\Attribute\ServiceActivator;
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
