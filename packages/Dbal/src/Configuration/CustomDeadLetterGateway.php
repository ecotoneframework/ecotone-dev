<?php

namespace Ecotone\Dbal\Configuration;

/**
 * licence Apache-2.0
 */
final class CustomDeadLetterGateway
{
    private function __construct(private string $gatewayReferenceName, private string $connectionReferenceName)
    {
    }

    public static function createWith(string $gatewayReferenceName, string $connectionReferenceName): self
    {
        return new self($gatewayReferenceName, $connectionReferenceName);
    }

    public function getGatewayReferenceName(): string
    {
        return $this->gatewayReferenceName;
    }

    public function getConnectionReferenceName(): string
    {
        return $this->connectionReferenceName;
    }
}
