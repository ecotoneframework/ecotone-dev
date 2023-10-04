<?php

namespace Test\Ecotone\Messaging\Unit\Handler\Gateway;

use Ecotone\Messaging\Config\NonProxyCombinedGateway;
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
    public function test_creating_with_cache_proxy()
    {
        $proxyFactory = ProxyFactory::createWithCache(sys_get_temp_dir());
        $data = 'someReply';

        /** @var StringReturningGateway $proxy */
        $proxy = $proxyFactory->createProxyClassWithAdapter(StringReturningGateway::class, new GatewayProxyAdapter(
            NonProxyCombinedGateway::createWith(
                'test',
                StringReturningGateway::class,
                ['executeNoParams' => new GatewayExecuteClass($data)]
            )
        ));

        $this->assertEquals($data, $proxy->executeNoParams());
    }
}
