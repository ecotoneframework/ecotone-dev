<?php

namespace Ecotone\Dbal\ObjectManager;

use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Throwable;

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
        /** @var ManagerRegistry[] $objectManagers */
        $objectManagers = [];

        foreach ($this->managerRegistryConnectionFactories as $managerRegistryConnectionFactory) {
            // TODO: this is always false
            if ($managerRegistryConnectionFactory instanceof ManagerRegistryConnectionFactory) {
                $objectManagers[] = DbalReconnectableConnectionFactory::getManagerRegistryAndConnectionName($managerRegistryConnectionFactory)[0];
            }
        }

        $this->depthCount++;
        try {
            $result = $methodInvocation->proceed();

            foreach ($objectManagers as $objectManager) {
                foreach ($objectManager->getManagers() as $manager) {
                    $manager->flush();
                    if ($this->depthCount === 1) {
                        $manager->clear();
                    }
                }
            }

            if (count($objectManagers) > 0) {
                $logger->info(
                    'Flushed and cleared doctrine object managers',
                    $message
                );
            }
        } catch (Throwable $exception) {
            foreach ($objectManagers as $objectManager) {
                foreach ($objectManager->getManagers() as $manager) {
                    $manager->clear();
                }
            }
            if (count($objectManagers) > 0) {
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
