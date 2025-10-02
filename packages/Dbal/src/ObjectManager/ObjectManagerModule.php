<?php

namespace Ecotone\Dbal\ObjectManager;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;
use Enqueue\Dbal\DbalConnectionFactory;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
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

        $pointcut = [];
        if ($dbalConfiguration->isClearObjectManagerOnAsynchronousEndpoints()) {
            $pointcut[] = AsynchronousRunningEndpoint::class;
        }
        if ($dbalConfiguration->isClearAndFlushObjectManagerOnCommandBus()) {
            $pointcut[] = CommandBus::class;
        }

        if ($pointcut !== []) {
            $connectionFactories = $dbalConfiguration->getDefaultConnectionReferenceNames() ?: [DbalConnectionFactory::class];
            $connectionFactoriesReferences = [];
            foreach ($connectionFactories as $connectionFactory) {
                $connectionFactoriesReferences[$connectionFactory] = new Reference($connectionFactory);
            }

            $messagingConfiguration->registerServiceDefinition(ObjectManagerInterceptor::class, [
                $connectionFactoriesReferences,
            ]);

            $messagingConfiguration
                ->registerAroundMethodInterceptor(
                    AroundInterceptorBuilder::create(
                        ObjectManagerInterceptor::class,
                        $interfaceToCallRegistry->getFor(ObjectManagerInterceptor::class, 'transactional'),
                        Precedence::DATABASE_OBJECT_MANAGER_PRECEDENCE,
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

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $serviceExtensions, DbalConfiguration::createWithDefaults());
        $repositories = [];

        if ($dbalConfiguration->isDoctrineORMRepositoriesEnabled()) {
            $repositories[] = new DoctrineORMRepositoryBuilder($dbalConfiguration);
        }

        return $repositories;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
