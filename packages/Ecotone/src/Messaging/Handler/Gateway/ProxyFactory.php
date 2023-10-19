<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gateway;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\EcotoneRemoteAdapter;
use Ecotone\Messaging\Config\GatewayReference;
use Ecotone\Messaging\Support\Assert;
use ProxyManager\Autoloader\AutoloaderInterface;
use ProxyManager\Configuration;
use ProxyManager\Factory\RemoteObject\AdapterInterface;
use ProxyManager\Factory\RemoteObjectFactory;
use ProxyManager\FileLocator\FileLocator;
use ProxyManager\GeneratorStrategy\FileWriterGeneratorStrategy;
use ProxyManager\Signature\ClassSignatureGenerator;
use ProxyManager\Signature\SignatureGenerator;

use function spl_autoload_register;
use function spl_autoload_unregister;

/**
 * Class LazyProxyConfiguration
 * @package Ecotone\Messaging\Handler\Gateway
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class ProxyFactory
{
    private ?AutoloaderInterface $registeredAutoloader = null;
    private ?Configuration $configuration = null;

    public function __construct(private ?string $cacheDirectory)
    {
    }

    private function getConfiguration(): Configuration
    {
        return $this->configuration ??= $this->buildConfiguration();
    }

    private function buildConfiguration(): Configuration
    {
        $configuration = new Configuration();

        if ($this->cacheDirectory) {
            $this->prepareCacheDirectory();
            $configuration->setProxiesTargetDir($this->cacheDirectory);
            $fileLocator = new FileLocator($configuration->getProxiesTargetDir());
            $configuration->setGeneratorStrategy(new FileWriterGeneratorStrategy($fileLocator));
            $configuration->setClassSignatureGenerator(new ClassSignatureGenerator(new SignatureGenerator()));
        }

        return $configuration;
    }

    public function createFor(string $referenceName, ConfiguredMessagingSystem $messagingSystem, string $interface): object
    {
        $factory = new RemoteObjectFactory(new EcotoneRemoteAdapter($messagingSystem, $referenceName), $this->getConfiguration());

        return $factory->createProxy($interface);
    }

    /**
     * @param GatewayReference[] $gatewayReferences
     */
    public function warmUp(array $gatewayReferences): void
    {
        $factory = new RemoteObjectFactory(new class () implements AdapterInterface {
            public function call(string $wrappedClass, string $method, array $params = [])
            {
            }
        }, $this->getConfiguration());

        foreach ($gatewayReferences as $gatewayReference) {
            $factory->createProxy($gatewayReference->getInterfaceName());
        }
    }

    public function registerProxyAutoloader(): void
    {
        if (! $this->cacheDirectory) {
            return;
        }
        if ($this->registeredAutoloader) {
            return;
        }

        $this->registeredAutoloader = $this->getConfiguration()->getProxyAutoloader();
        spl_autoload_register($this->registeredAutoloader);
    }

    public function unRegisterProxyAutoloader(): void
    {
        if (! $this->cacheDirectory) {
            return;
        }
        if (! $this->registeredAutoloader) {
            return;
        }
        spl_autoload_unregister($this->registeredAutoloader);
        $this->registeredAutoloader = null;
    }

    public function prepareCacheDirectory(): void
    {
        if (! $this->cacheDirectory) {
            return;
        }
        if (! is_dir($this->cacheDirectory)) {
            $mkdirResult = @mkdir($this->cacheDirectory, 0775, true);
            Assert::isTrue(
                $mkdirResult,
                "Not enough permissions to create cache directory {$this->cacheDirectory}"
            );
        }

        Assert::isFalse(is_file($this->cacheDirectory), 'Cache directory is file, should be directory');
    }

    public function clearCache()
    {
        Assert::isTrue(
            is_writable($this->cacheDirectory),
            "Not enough permissions to delete cache directory {$this->cacheDirectory}"
        );
        $files = glob($this->cacheDirectory.'/*', GLOB_MARK);
        foreach($files as $file) {
            if(is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->cacheDirectory);
    }
}
