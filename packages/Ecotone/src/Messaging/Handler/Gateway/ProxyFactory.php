<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gateway;

use Ecotone\Messaging\Config\ConfigurationException;
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
        ], [self::class, "createProxyInstance"]);
    }

    public function createProxyInstance(GatewayProxyReference $proxyReference, ConfiguredMessagingSystem $messagingSystem): object
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
            $this->dumpFile($file, $code);
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


    private function dumpFile(string $fileName, string $code): void
    {
        // Code adapted from doctrine/orm/src/Proxy/ProxyFactory.php
        $parentDirectory = dirname($fileName);

        if (! is_dir($parentDirectory) && ! @mkdir($parentDirectory, 0775, true)) {
            throw ConfigurationException::create("Cannot create cache directory {$parentDirectory}");
        }

        if (! is_writable($parentDirectory)) {
            throw ConfigurationException::create("Cache directory is not writable {$parentDirectory}");
        }

        $tmpFileName = $fileName . '.' . bin2hex(random_bytes(12));

        file_put_contents($tmpFileName, $code);
        @chmod($tmpFileName, 0664);
        rename($tmpFileName, $fileName);
    }
}
