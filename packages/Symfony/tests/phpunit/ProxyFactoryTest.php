<?php

namespace Test;

use Ecotone\SymfonyBundle\Proxy\ProxyFactory;
use Fixture\User\UserRepository;
use PHPUnit\Framework\TestCase;

class ProxyFactoryTest extends TestCase
{
    public function test_generating_proxy()
    {
        $proxyFactory = new ProxyFactory("TestingNamespace");
        $code = $proxyFactory->generateProxyFor(InterfaceForProxyGeneration::class);

        $this->assertEquals(
            \file_get_contents(__DIR__ . "/ProxyFactoryTest.snapshot"),
            $code);
    }
}

/**
 * @internal
 */
interface InterfaceForProxyGeneration
{
    public function doSomething() : void;

    public function doSomethingAndReturnSomething(): mixed;

    public function doSomethingWithDefaultParameter(array $param = []): mixed;
}
