<?php

namespace Ecotone\Messaging\Config;

use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\Support\Assert;

class NonProxyCombinedGateway
{
    private string $referenceName;
    /**
     * @var NonProxyGateway[]
     */
    private array $methodGateways;
    private string $interfaceName;

    /**
     * @param NonProxyGateway[] $methodGateways
     */
    private function __construct(
        string $referenceName,
        string $interfaceName,
        array $methodGateways
    )
    {
        $this->referenceName  = $referenceName;
        $this->methodGateways = $methodGateways;
        $this->interfaceName = $interfaceName;
    }

    /**
     * @param NonProxyGateway[] $methodGateways
     */
    public static function createWith(string $referenceName, string $interfaceName, array $methodGateways): self
    {
        return new self($referenceName, $interfaceName, $methodGateways);
    }

    /**
     * @return string
     */
    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    public function getInterfaceName(): string
    {
        return $this->interfaceName;
    }

    /**
     * @param string $referenceName
     * @return bool
     */
    public function hasReferenceName(string $referenceName): bool
    {
        return $this->referenceName == $referenceName;
    }

    public function executeMethod(string $methodName, array $params)
    {
        Assert::keyExists($this->methodGateways, $methodName, "Can't call gateway {$this->referenceName} with method {$methodName}. The method does not exists");

        return $this->methodGateways[$methodName]->execute($params);
    }

    public function getMethodGateways(): array
    {
        return $this->methodGateways;
    }
}
