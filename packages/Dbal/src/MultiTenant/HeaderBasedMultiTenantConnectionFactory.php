<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Messaging\Channel\DynamicChannel\ReceivingStrategy\RoundRobinReceivingStrategy;
use Ecotone\Messaging\Config\ConnectionReference;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use Psr\Container\ContainerInterface;

/**
 * licence Apache-2.0
 */
final class HeaderBasedMultiTenantConnectionFactory implements MultiTenantConnectionFactory
{
    public const TENANT_ACTIVATED_CHANNEL_NAME = 'ecotone.multi_tenant_propagation_channel.activate';
    public const TENANT_DEACTIVATED_CHANNEL_NAME = 'ecotone.multi_tenant_propagation_channel.deactivate';

    /**
     * @param array<string, string|ConnectionReference> $connectionReferenceMapping
     * @param array<string, ConnectionFactory> $container
     * @param string|null $pollingConsumerTenant
     */
    public function __construct(
        private string              $tenantHeaderName,
        private array               $connectionReferenceMapping,
        private MessagingEntrypoint $messagingEntrypoint,
        private ContainerInterface  $container,
        private LoggingGateway $loggingGateway,
        private RoundRobinReceivingStrategy $roundRobinReceivingStrategy,
        private string|ConnectionReference|null $defaultConnectionName = null,
        private ?string $pollingConsumerTenant = null,
    ) {

    }

    public function enablePollingConsumerPropagation(): void
    {
        $this->pollingConsumerTenant = $this->roundRobinReceivingStrategy->decide();
    }

    public function disablePollingConsumerPropagation(): void
    {
        $this->pollingConsumerTenant = null;
    }

    public function getManager(?string $tenant = null): ObjectManager
    {
        if ($tenant !== null) {
            $connectionReference = $this->getCurrentConnectionReferenceOrNull($tenant);
            Assert::notNull($connectionReference, "Lack of mapping for tenant `{$tenant}`. Please provide mapping for this tenant or default connection name.");
        } else {
            $connectionReference = $this->getCurrentConnectionReferenceOrNull();
            Assert::notNull($connectionReference, "Lack of context about tenant in Message Headers. Please add `{$this->tenantHeaderName}` header metadata to your message.");
        }

        $connectionFactory = $this->getConnectionFactory();
        Assert::isTrue($connectionFactory instanceof EcotoneManagerRegistryConnectionFactory, 'Connection factory was not registered by `DbalConnection::createForManagerRegistry()`');

        /** @var ManagerRegistry $managerRegistry */
        $managerRegistry = $connectionFactory->getRegistry();

        return $managerRegistry->getManager($connectionReference instanceof ConnectionReference ? $connectionReference->getConnectionName() : null);
    }

    public function getConnection(?string $tenant = null): Connection
    {
        if ($tenant !== null) {
            $connectionReference = $this->getCurrentConnectionReferenceOrNull($tenant);
            Assert::notNull($connectionReference, "Lack of mapping for tenant `{$tenant}`. Please provide mapping for this tenant or default connection name.");
        } else {
            $connectionReference = $this->getCurrentConnectionReferenceOrNull();
            Assert::notNull($connectionReference, "Lack of context about tenant in Message Headers. Please add `{$this->tenantHeaderName}` header metadata to your message.");
        }

        /** @var DbalContext $dbalConnection */
        $dbalConnection = $this->container->get($this->getConnectionReference($connectionReference))->createContext();
        Assert::isTrue($dbalConnection instanceof DbalContext, 'Connection factory was not registered using by Ecotone\Dbal\DbalConnection::*');

        return $dbalConnection->getDbalConnection();
    }

    public function getConnectionFactory(): ConnectionFactory
    {
        $connectionReference = $this->getCurrentConnectionReferenceOrNull();

        if ($connectionReference === null) {
            $tenant = $this->getCurrentTenantOrNull();

            if ($tenant === null) {
                throw new InvalidArgumentException("Lack of context about tenant in Message Headers. Please add `{$this->tenantHeaderName}` header metadata to your message.");
            } else {
                throw new InvalidArgumentException("Lack of mapping for tenant `{$tenant}`. Please provide mapping for this tenant or default connection name.");
            }
        }

        $connection = $this->container->get($this->getConnectionReference($connectionReference));
        Assert::isTrue(
            $connection instanceof ConnectionFactory,
            sprintf('Connection reference %s, does not return ConnectionFactory. Please check if you have registered it correctly.', (string)$connectionReference)
        );

        return $connection;
    }

    public function currentActiveTenant(): string
    {
        $tenant = $this->getCurrentTenantOrNull();

        if ($tenant === null) {
            throw new InvalidArgumentException("Lack of context about tenant in Message Headers. Please add `{$this->tenantHeaderName}` header metadata to your message.");
        }

        return $tenant;
    }

    public function hasActiveTenant(): bool
    {
        return $this->getCurrentTenantOrNull() !== null;
    }

    private string|null $currentActiveTenant = null;

    public function propagateTenant(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $tenant = $this->getCurrentTenantOrNull();
        if ($tenant === null || $tenant === $this->currentActiveTenant) {
            return $methodInvocation->proceed();
        }

        if ($this->currentActiveTenant !== null) {
            throw new InvalidArgumentException("Tenant `{$tenant}` is already active. Please deactivate it before activating another tenant.");
        }

        /** @var string|ConnectionReference $connectionReference */
        $connectionReference = $this->getCurrentConnectionReferenceOrNull($tenant);
        Assert::notNull($connectionReference, "Lack of mapping for tenant `{$tenant}`. Please provide mapping for this tenant or default connection name.");
        $this->currentActiveTenant = $tenant;

        try {
            $this->loggingGateway->info("Activating tenant `{$tenant}` on connection `{$connectionReference}`", $message);
            $this->messagingEntrypoint->sendWithHeaders($connectionReference, [$this->tenantHeaderName => $tenant], self::TENANT_ACTIVATED_CHANNEL_NAME);

            $result = $methodInvocation->proceed();
        } finally {
            $this->currentActiveTenant = null;
            $this->loggingGateway->info("Deactivating tenant `{$tenant}` on connection `{$connectionReference}`", $message);
            $this->messagingEntrypoint->sendWithHeaders($connectionReference, [$this->tenantHeaderName => $tenant], self::TENANT_DEACTIVATED_CHANNEL_NAME);
        }

        return $result;
    }

    private function getCurrentTenantOrNull(): ?string
    {
        if ($this->pollingConsumerTenant !== null) {
            return $this->pollingConsumerTenant;
        }

        $headers = $this->messagingEntrypoint->send([], MessageHeadersPropagatorInterceptor::GET_CURRENTLY_PROPAGATED_HEADERS_CHANNEL);

        if (! array_key_exists($this->tenantHeaderName, $headers)) {
            return null;
        }

        return $headers[$this->tenantHeaderName];
    }

    public function getCurrentConnectionReferenceOrNull(?string $tenant = null): string|ConnectionReference|null
    {
        $currentTenant = $tenant ?? $this->getCurrentTenantOrNull();

        if (isset($this->connectionReferenceMapping[$currentTenant])) {
            return $this->connectionReferenceMapping[$currentTenant];
        }

        if ($this->defaultConnectionName === null) {
            return null;
        }

        return $this->defaultConnectionName;
    }

    public function createContext(): Context
    {
        return $this->getConnectionFactory()->createContext();
    }

    private function getConnectionReference(ConnectionReference|string|null $connectionReference): string
    {
        return $connectionReference instanceof ConnectionReference ? $connectionReference->getReferenceName() : (string)$connectionReference;
    }
}
