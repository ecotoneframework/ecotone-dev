<?php

namespace Ecotone\Messaging\Handler\Gateway\Proxy;

use Closure;
use ProxyManager\Factory\RemoteObject\AdapterInterface;

class ProxyWithBuildCallback implements AdapterInterface
{
    private Closure $buildCallback;

    /**
     *  constructor.
     *
     * @param Closure $buildCallback
     */
    public function __construct(Closure $buildCallback)
    {
        $this->buildCallback = $buildCallback;
    }

    /**
     * @inheritDoc
     */
    public function call(string $wrappedClass, string $method, array $params = [])
    {
        $buildCallback = $this->buildCallback;
        $gateway = $buildCallback();

        return $gateway->execute($params);
    }
}