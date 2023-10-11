<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config;

use Ecotone\Messaging\Handler\Gateway\Gateway;
use ProxyManager\Factory\RemoteObject\AdapterInterface;
use Psr\Container\ContainerInterface;

class EcotoneRemoteAdapter implements AdapterInterface
{
    public function __construct(private ConfiguredMessagingSystem $messagingSystem, private string $referenceName)
    {
    }

    public function call(string $wrappedClass, string $method, array $params = [])
    {
        /** @var Gateway $gateway */
        $gateway = $this->messagingSystem->getNonProxyGatewayByName($this->referenceName.'::'.$method);
        return $gateway->execute($params);
    }
}
