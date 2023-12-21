<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\MultiTenantConnectionFactory;

use Ecotone\Messaging\Channel\DynamicChannel\ReceivingStrategy\RoundRobinReceivingStrategy;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use Psr\Container\ContainerInterface;

final class MultiTenantConnectionFactory implements ConnectionFactory
{
    /**
     * @param array<string, string> $connectionReferenceMapping
     * @param array<string, ConnectionFactory> $container
     */
    public function __construct(
        private string              $tenantHeaderName,
        private array               $connectionReferenceMapping,
        private MessagingEntrypoint $messagingEntrypoint,
        private ContainerInterface  $container,
        private RoundRobinReceivingStrategy $roundRobinReceivingStrategy,
        private ?string             $defaultConnectionName = null,
    )
    {

    }

    public function createContext(): Context
    {
        $headers = $this->messagingEntrypoint->send([], MessageHeadersPropagatorInterceptor::GET_CURRENTLY_PROPAGATED_HEADERS_CHANNEL);

        if ($headers === []) {
            $isPollingConsumer = $this->messagingEntrypoint->send([], MessageHeadersPropagatorInterceptor::IS_POLLING_CONSUMER_PROPAGATION_CONTEXT);
            if ($isPollingConsumer) {
                return $this->container->get($this->connectionReferenceMapping[$this->roundRobinReceivingStrategy->decide()])->createContext();
            }

            throw new InvalidArgumentException('Using multi tenant connection factory without Message context, you most likely need to set up Dynamic Message Channel for fetching. Please check your configuration and documentation about multi tenancy connections.');
        }

        if (!array_key_exists($this->tenantHeaderName, $headers)) {
            throw new InvalidArgumentException("Lack of context about tenant in Message Headers. Please add {$this->tenantHeaderName} header metadata to your message.");
        }

        if (isset($this->connectionReferenceMapping[$headers[$this->tenantHeaderName]])) {
            return $this->container->get($this->connectionReferenceMapping[$headers[$this->tenantHeaderName]])->createContext();
        }

        if ($this->defaultConnectionName === null) {
            throw new InvalidArgumentException("Lack of mapping for tenant `{$headers[$this->tenantHeaderName]}`. Please provide mapping for this tenant or default connection name.");
        }

        return $this->container->get($this->defaultConnectionName)->createContext();
    }
}