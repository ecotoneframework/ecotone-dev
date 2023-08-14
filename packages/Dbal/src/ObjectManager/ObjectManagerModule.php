<?php

namespace Ecotone\Dbal\ObjectManager;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
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
class ObjectManagerModule implements AnnotationModule
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
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());

        $connectionFactories = [DbalConnectionFactory::class];
        if ($dbalConfiguration->getDefaultConnectionReferenceNames()) {
            $connectionFactories = $dbalConfiguration->getDefaultConnectionReferenceNames();
        }

        $pointcut = [];
        if ($dbalConfiguration->isClearObjectManagerOnAsynchronousEndpoints()) {
            $pointcut[] = AsynchronousRunningEndpoint::class;
        }
        if ($dbalConfiguration->isClearAndFlushObjectManagerOnCommandBus()) {
            $pointcut[] = CommandBus::class;
        }

        if ($pointcut !== []) {
            $messagingConfiguration
                ->requireReferences($connectionFactories)
                ->registerAroundMethodInterceptor(
                    AroundInterceptorReference::createWithDirectObjectAndResolveConverters(
                        $interfaceToCallRegistry,
                        new ObjectManagerInterceptor($connectionFactories),
                        'transactional',
                        Precedence::DATABASE_TRANSACTION_PRECEDENCE + 1,
                        implode('||', $pointcut)
                    )
                );
        }
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
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $serviceExtensions, DbalConfiguration::createWithDefaults());
        $repositories = [];

        if ($dbalConfiguration->isDoctrineORMRepositoriesEnabled()) {
            $repositories[] = new DoctrineORMRepositoryBuilder($dbalConfiguration->getDoctrineORMRepositoryConnectionReference(), $dbalConfiguration->getDoctrineORMClasses());
        }

        return $repositories;
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
