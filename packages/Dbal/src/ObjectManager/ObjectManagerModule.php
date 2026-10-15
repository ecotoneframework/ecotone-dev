<?php

namespace Ecotone\Dbal\ObjectManager;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Projecting\ProjectionFlush;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class ObjectManagerModule implements AnnotationModule
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
        if ($dbalConfiguration->clearObjectManagerOnAsynchronousEndpoints) {
            $pointcut[] = AsynchronousRunningEndpoint::class;
        }
        if ($dbalConfiguration->clearAndFlushObjectManagerOnCommandBus) {
            $pointcut[] = CommandBus::class;
        }
        if ($dbalConfiguration->clearAndFlushObjectManagerOnProjectionBatch) {
            $pointcut[] = ProjectionFlush::class;
        }

        if ($pointcut !== []) {
            $connectionFactories = $dbalConfiguration->defaultConnectionReferenceNames ?: [DbalConnectionReference::DEFAULT];
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
    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $serviceExtensions, DbalConfiguration::createWithDefaults());
        $repositories = [];

        if ($dbalConfiguration->enableDoctrineORMRepositories) {
            $repositories[] = new DoctrineORMRepositoryBuilder($dbalConfiguration);
        }

        return $repositories;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
