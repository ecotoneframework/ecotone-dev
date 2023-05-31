<?php

namespace Ecotone\Messaging\Handler;

use Psr\Container\ContainerInterface;

interface ServiceProviderInterface extends ContainerInterface
{
    /**
     * Returns an associative array of service types keyed by the identifiers provided by the current container.
     *
     * Examples:
     *
     *  * ['logger' => 'Psr\Log\LoggerInterface'] means the object provides a service named "logger" that implements Psr\Log\LoggerInterface
     *  * ['foo' => '?'] means the container provides service name "foo" of unspecified type
     *  * ['bar' => '?Bar\Baz'] means the container provides a service "bar" of type Bar\Baz|null
     *
     * @return string[] The provided service types, keyed by service names
     */
    public function getProvidedServices(): array;
}