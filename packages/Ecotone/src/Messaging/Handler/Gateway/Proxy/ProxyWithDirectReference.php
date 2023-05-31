<?php

namespace Ecotone\Messaging\Handler\Gateway\Proxy;


use Ecotone\Messaging\Handler\NonProxyGateway;
use ProxyManager\Factory\RemoteObject\AdapterInterface;

class ProxyWithDirectReference implements AdapterInterface
{
    private NonProxyGateway $gateway;

    public function __construct(NonProxyGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * @inheritDoc
     */
    public function call(string $wrappedClass, string $method, array $params = [])
    {
        return $this->gateway->execute($params);
    }
}