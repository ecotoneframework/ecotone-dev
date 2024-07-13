<?php

namespace Ecotone\Messaging\Endpoint;

/**
 * Class NullEntrypointGateway
 * @package Ecotone\Messaging\Endpoint
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 */
class NullInboundGatewayEntrypoint implements InboundGatewayEntrypoint
{
    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @inheritDoc
     */
    public function executeEntrypoint($data): void
    {
    }
}
