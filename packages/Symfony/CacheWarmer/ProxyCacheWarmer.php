<?php

namespace Ecotone\SymfonyBundle\CacheWarmer;

use Ecotone\SymfonyBundle\Proxy\ProxyFactory;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class ProxyCacheWarmer implements CacheWarmerInterface
{
    public function __construct(private iterable $proxiedInterfaces, private ProxyFactory $proxyFactory, private string $proxyDirectoryPath)
    {
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir): void
    {
        if (!is_dir($this->proxyDirectoryPath)) {
            if (false === @mkdir($this->proxyDirectoryPath, 0777, true) && !is_dir($this->proxyDirectoryPath)) {
                throw new \RuntimeException(sprintf('Unable to create the Ecotone Proxy directory "%s".', $this->proxyDirectoryPath));
            }
        } elseif (!is_writable($this->proxyDirectoryPath)) {
            throw new \RuntimeException(sprintf('The Ecotone Proxy directory "%s" is not writeable for the current system user.', $this->proxyDirectoryPath));
        }

        foreach ($this->proxiedInterfaces as $interface) {
            $classname = $this->proxyFactory->getClassNameFor($interface);
            $code = $this->proxyFactory->generateProxyFor($interface);
            file_put_contents($this->proxyDirectoryPath . "/" . $classname . ".php", "<?php \n" . $code);
        }
    }
}