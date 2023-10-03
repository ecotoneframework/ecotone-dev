<?php

namespace Test\Ecotone\Messaging\Unit\Handler\Gateway;

use Ecotone\Messaging\Handler\Gateway\GatewayProxyAdapter;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\GatewayExecuteClass;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\StringReturningGateway;

/**
 * @internal
 */
class ProxyFactoryTest extends TestCase
{
    public function test_creating_no_cache_proxy()
    {
        $proxyFactory = ProxyFactory::createNoCache();
        $data = 'someReply';
        $proxyFactory = unserialize(serialize($proxyFactory));

        /** @var StringReturningGateway $proxy */
        $proxy = $proxyFactory->createProxyClassWithAdapter(StringReturningGateway::class, new GatewayProxyAdapter(['executeNoParams' => new GatewayExecuteClass($data)]));

        $this->assertEquals($data, $proxy->executeNoParams());
    }
}
