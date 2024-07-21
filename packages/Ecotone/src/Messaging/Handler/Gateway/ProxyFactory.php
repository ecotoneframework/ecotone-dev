<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gateway;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\GatewayProxyReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;

/**
 * Class LazyProxyConfiguration
 * @package Ecotone\Messaging\Handler\Gateway
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class ProxyFactory
{
    public const PROXY_NAMESPACE = "Ecotone\\__Proxy__";

    private ProxyGenerator $proxyGenerator;

    public function __construct(private ServiceCacheConfiguration $serviceCacheConfiguration)
    {
        $this->proxyGenerator = new ProxyGenerator(self::PROXY_NAMESPACE);
    }

    public static function getGatewayProxyDefinitionFor(GatewayProxyReference $proxyReference): Definition
    {
        return new Definition(self::getClassNameFor($proxyReference->getInterfaceName()), [
            new Definition(GatewayProxyReference::class, [
                $proxyReference->getReferenceName(),
                $proxyReference->getInterfaceName()
            ]),
            new Reference(ConfiguredMessagingSystem::class),
        ], [self::class, "createProxyFor"]);
    }

    public function createProxyFor(GatewayProxyReference $proxyReference, ConfiguredMessagingSystem $messagingSystem): object
    {
        $proxyClassName = self::getFullClassNameFor($proxyReference->getInterfaceName());
        if (! class_exists($proxyClassName, false)) {
            if ($this->serviceCacheConfiguration->shouldUseCache()) {
                $file = $this->generateCachedProxyFileFor($proxyReference, false);
                require $file;
            } else {
                $code = $this->proxyGenerator->generateProxyFor($proxyClassName, $proxyReference->getInterfaceName());
                eval(\substr($code, 5));
            }
        }
        return new $proxyClassName($messagingSystem, $proxyReference);
    }

    public function generateCachedProxyFileFor(GatewayProxyReference $proxyReference, bool $overwrite): string
    {
        $proxyClassName = self::getFullClassNameFor($proxyReference->getInterfaceName());
        $file = $this->getFilePathForProxy($proxyReference->getInterfaceName());
        if ($overwrite || ! \file_exists($file)) {
            $code = $this->proxyGenerator->generateProxyFor($proxyClassName, $proxyReference->getInterfaceName());
            \file_put_contents($file, $code);
        }
        return $file;
    }

    private function getFilePathForProxy(string $interfaceName) : string
    {
        $className = self::getClassNameFor($interfaceName);
        return $this->serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . $className . ".php";
    }

    private static function getClassNameFor(string $interfaceName): string
    {
        return \str_replace("\\", "_", $interfaceName);
    }

    private function getFullClassNameFor(string $interfaceName): string
    {
        return self::PROXY_NAMESPACE . "\\" . $this->getClassNameFor($interfaceName);
    }
}
