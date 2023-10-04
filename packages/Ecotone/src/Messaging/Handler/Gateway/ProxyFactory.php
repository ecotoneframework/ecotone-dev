<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gateway;

use Ecotone\Messaging\Config\EcotoneRemoteAdapter;
use ProxyManager\Configuration;
use ProxyManager\Factory\LazyLoadingValueHolderFactory;
use ProxyManager\Factory\RemoteObject\AdapterInterface;
use ProxyManager\Factory\RemoteObjectFactory;
use ProxyManager\FileLocator\FileLocator;
use ProxyManager\GeneratorStrategy\FileWriterGeneratorStrategy;
use ProxyManager\Proxy\RemoteObjectInterface;
use ProxyManager\Signature\ClassSignatureGenerator;
use ProxyManager\Signature\SignatureGenerator;
use Psr\Container\ContainerInterface;
use Serializable;
use stdClass;

/**
 * Class LazyProxyConfiguration
 * @package Ecotone\Messaging\Handler\Gateway
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class ProxyFactory
{
    public const REFERENCE_NAME = 'gatewayProxyConfiguration';

    private string $cacheDirectoryPath;

    private function __construct(string $cacheDirectoryPath)
    {
        $this->cacheDirectoryPath = $cacheDirectoryPath;
    }

    public static function createWithCache(string $cacheDirectoryPath): self
    {
        return new self($cacheDirectoryPath);
    }

    /**
     * @return Configuration
     */
    public function getConfiguration(): Configuration
    {
        $configuration = new Configuration();

        if ($this->cacheDirectoryPath) {
            $configuration->setProxiesTargetDir($this->cacheDirectoryPath);
            $fileLocator = new FileLocator($configuration->getProxiesTargetDir());
            $configuration->setGeneratorStrategy(new FileWriterGeneratorStrategy($fileLocator));
            $configuration->setClassSignatureGenerator(new ClassSignatureGenerator(new SignatureGenerator()));
        }

        return $configuration;
    }

    public function createProxyClassWithAdapter(string $interfaceName, AdapterInterface $adapter): RemoteObjectInterface
    {
        $factory = new RemoteObjectFactory($adapter, $this->getConfiguration());

        return $factory->createProxy($interfaceName);
    }

    public static function createFor(string $referenceName, ContainerInterface $container, string $interface, string $cacheDirectoryPath): object
    {
        $proxyFactory = self::createWithCache($cacheDirectoryPath);

        return $proxyFactory->createProxyClassWithAdapter(
            $interface,
            new EcotoneRemoteAdapter($container, $referenceName)
        );
    }
}
