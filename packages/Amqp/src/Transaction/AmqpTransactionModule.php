<?php

namespace Ecotone\Amqp\Transaction;

use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;
use Enqueue\AmqpExt\AmqpConnectionFactory;

#[ModuleAnnotation]
class AmqpTransactionModule implements AnnotationModule
{
    private function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $connectionFactories = [AmqpConnectionFactory::class];
        $pointcut = AmqpTransaction::class;
        $amqpConfiguration = ExtensionObjectResolver::resolveUnique(AmqpConfiguration::class, $extensionObjects, AmqpConfiguration::createWithDefaults());
        ;

        $isTransactionWrapperEnabled = false;
        if ($amqpConfiguration->isTransactionOnAsynchronousEndpoints()) {
            $pointcut .= '||' . AsynchronousRunningEndpoint::class;
            $isTransactionWrapperEnabled = true;
        }
        if ($amqpConfiguration->isTransactionOnCommandBus()) {
            $pointcut .= '||' . CommandBus::class . '';
            $isTransactionWrapperEnabled = true;
        }
        if ($amqpConfiguration->isTransactionOnConsoleCommands()) {
            $pointcut .= '||' . ConsoleCommand::class . '';
            $isTransactionWrapperEnabled = true;
        }
        if ($amqpConfiguration->getDefaultConnectionReferenceNames()) {
            $connectionFactories = $amqpConfiguration->getDefaultConnectionReferenceNames();
        }

        if ($isTransactionWrapperEnabled) {
            $messagingConfiguration->requireReferences($connectionFactories);
        }

        $messagingConfiguration
            ->registerAroundMethodInterceptor(
                AroundInterceptorReference::createWithDirectObjectAndResolveConverters(
                    $interfaceToCallRegistry,
                    new AmqpTransactionInterceptor($connectionFactories),
                    'transactional',
                    Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1,
                    $pointcut
                )
            );
    }

    public function getModuleExtensions(array $serviceExtensions): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof AmqpConfiguration;
    }

    /**
     * @inheritDoc
     */
    public function getRelatedReferences(): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}
