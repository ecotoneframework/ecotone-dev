<?php

namespace Ecotone\Dbal\DbalTransaction;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Configuration\DbalModule;
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
use Enqueue\Dbal\DbalConnectionFactory;

#[ModuleAnnotation]
class DbalTransactionModule implements AnnotationModule
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
    public function prepare(Configuration $configuration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $connectionFactories = [DbalConnectionFactory::class];
        $pointcut            = '(' . DbalTransaction::class . ')';

        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());

        if ($dbalConfiguration->isTransactionOnAsynchronousEndpoints()) {
            $pointcut .= '||(' . AsynchronousRunningEndpoint::class . ')';
        }
        if ($dbalConfiguration->isTransactionOnCommandBus()) {
            $pointcut .= '||(' . CommandBus::class . ')';
        }
        if ($dbalConfiguration->isTransactionOnConsoleCommands()) {
            $pointcut .= '||(' . ConsoleCommand::class . ')';
        }
        if ($dbalConfiguration->getDefaultConnectionReferenceNames()) {
            $connectionFactories = $dbalConfiguration->getDefaultConnectionReferenceNames();
        }

        $configuration
            ->requireReferences($connectionFactories)
            ->registerAroundMethodInterceptor(
                AroundInterceptorReference::createWithDirectObjectAndResolveConverters(
                    $interfaceToCallRegistry,
                    new DbalTransactionInterceptor($connectionFactories),
                    'transactional',
                    Precedence::DATABASE_TRANSACTION_PRECEDENCE,
                    $pointcut
                )
            );
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration;
    }

    public function getModuleExtensions(array $serviceExtensions): array
    {
        return [];
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
        return ModulePackageList::DBAL_PACKAGE;
    }
}
