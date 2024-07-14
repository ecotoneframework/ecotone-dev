<?php

namespace Ecotone\Dbal\ObjectManager;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Throwable;

/**
 * licence Apache-2.0
 */
class ObjectManagerInterceptor
{
    private int $depthCount = 0;

    /**
     * @param ManagerRegistryConnectionFactory[] $managerRegistryConnectionFactories
     */
    public function __construct(private array $managerRegistryConnectionFactories)
    {
    }

    public function transactional(MethodInvocation $methodInvocation, Message $message, #[Reference] LoggingGateway $logger)
    {
        /** @var ManagerRegistry[] $managerRegistries */
        $managerRegistries = [];

        foreach ($this->managerRegistryConnectionFactories as $managerRegistryConnectionFactory) {
            if ($managerRegistryConnectionFactory instanceof MultiTenantConnectionFactory) {
                $managerRegistryConnectionFactory = $managerRegistryConnectionFactory->getConnectionFactory();
            }

            if ($managerRegistryConnectionFactory instanceof EcotoneManagerRegistryConnectionFactory) {
                $managerRegistries[] = $managerRegistryConnectionFactory->getRegistry();
            }
        }

        $this->depthCount++;
        try {
            foreach ($managerRegistries as $managerRegistry) {
                /** @var EntityManagerInterface $objectManager */
                foreach ($managerRegistry->getManagers() as $name => $objectManager) {
                    if (! $objectManager->isOpen()) {
                        $managerRegistry->resetManager($name);
                    }
                    if ($this->depthCount === 1) {
                        $objectManager->clear();
                    }
                }
            }

            $result = $methodInvocation->proceed();

            foreach ($managerRegistries as $managerRegistry) {
                foreach ($managerRegistry->getManagers() as $objectManager) {
                    $objectManager->flush();
                    if ($this->depthCount === 1) {
                        $objectManager->clear();
                    }
                }
            }

            if (count($managerRegistries) > 0) {
                $logger->info(
                    'Flushed and cleared doctrine object managers',
                    $message
                );
            }
        } catch (Throwable $exception) {
            foreach ($managerRegistries as $managerRegistry) {
                foreach ($managerRegistry->getManagers() as $objectManager) {
                    $objectManager->clear();
                }
            }
            if (count($managerRegistries) > 0) {
                $logger->info(
                    'Cleared doctrine object managers',
                    $message
                );
            }

            throw $exception;
        } finally {
            $this->depthCount--;
        }


        return $result;
    }
}
