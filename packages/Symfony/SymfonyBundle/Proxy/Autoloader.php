<?php

namespace Ecotone\SymfonyBundle\Proxy;

class Autoloader
{
    public const PROXY_NAMESPACE = "Ecotone\\__Proxy__";
    public function __construct(private string $proxyDirectoryPath)
    {
    }

    public static function register(string $proxyDirectoryPath): self
    {
        $autoloader = new self($proxyDirectoryPath);
        spl_autoload_register($autoloader);
        return $autoloader;
    }

    public function __invoke(string $className): bool
    {
        if (class_exists($className, false) || ! $this->isProxyClassName($className)) {
            return false;
        }

        $file = $this->proxyDirectoryPath . DIRECTORY_SEPARATOR . str_replace(self::PROXY_NAMESPACE."\\", "", $className) . ".php";

        if (! file_exists($file)) {
            return false;
        }

        /* @noinspection PhpIncludeInspection */
        /* @noinspection UsingInclusionOnceReturnValueInspection */
        return (bool) require_once $file;
    }

    private function isProxyClassName(string $className)
    {
        return strpos($className, self::PROXY_NAMESPACE) === 0;
    }
}