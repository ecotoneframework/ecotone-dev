<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;

/**
 * Class InterceptedConsumerBuilder
 * @package Ecotone\Messaging\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
abstract class InterceptedChannelAdapterBuilder implements ChannelAdapterConsumerBuilder
{
    protected ?string $endpointId = null;
    protected InboundChannelAdapterEntrypoint|GatewayProxyBuilder $inboundGateway;

    protected function withContinuesPolling(): bool
    {
        return true;
    }
}
