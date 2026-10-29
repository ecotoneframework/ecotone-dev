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
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

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
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return $repositories;
        }

        $flushedAfter = array_keys(array_filter([
            'a command bus call' => $dbalConfiguration->clearAndFlushObjectManagerOnCommandBus,
            'a message an asynchronous endpoint consumes' => $dbalConfiguration->clearObjectManagerOnAsynchronousEndpoints,
            'a projection batch' => $dbalConfiguration->clearAndFlushObjectManagerOnProjectionBatch,
        ]));

        return [
            ...$repositories,
            new PulseFeature(
                package: ModulePackageList::DBAL_PACKAGE,
                name: 'Doctrine ORM flush and clear',
                enabled: $flushedAfter !== [],
                settings: [
                    'Command bus' => PulseFeature::onOff($dbalConfiguration->clearAndFlushObjectManagerOnCommandBus),
                    'Asynchronous endpoints' => PulseFeature::onOff($dbalConfiguration->clearObjectManagerOnAsynchronousEndpoints),
                    'Projection batches' => PulseFeature::onOff($dbalConfiguration->clearAndFlushObjectManagerOnProjectionBatch),
                ],
                handlers: [],
                hint: $flushedAfter === []
                    ? 'Ecotone does not flush or clear the Doctrine entity manager: handlers flush on their own, and a long-running consumer keeps the entities it loaded in memory from one message to the next.'
                    : sprintf('When the service uses Doctrine ORM, its entity manager is flushed after %s, then cleared: what a handler changed is written without an explicit flush, and the next message reads its entities fresh instead of from what a previous message left in memory.', implode(', after ', $flushedAfter)),
                configuration: 'DbalConfiguration::createWithDefaults()->withClearAndFlushObjectManagerOnCommandBus(true)->withClearAndFlushObjectManagerOnAsynchronousEndpoints(true)->withClearAndFlushObjectManagerOnProjectionBatch(true)',
            ),
            new PulseFeature(
                package: ModulePackageList::DBAL_PACKAGE,
                name: 'Doctrine ORM repositories',
                enabled: $dbalConfiguration->enableDoctrineORMRepositories,
                settings: $dbalConfiguration->enableDoctrineORMRepositories ? [
                    'Connection' => $dbalConfiguration->doctrineORMRepositoryConnectionReference ?? DbalConnectionReference::DEFAULT,
                    'Aggregates' => $dbalConfiguration->doctrineORMClasses === null ? 'every aggregate that is an ORM entity' : implode(', ', array_map(PulseFeature::shortNameOf(...), $dbalConfiguration->doctrineORMClasses)),
                ] : [],
                handlers: [],
                hint: $dbalConfiguration->enableDoctrineORMRepositories
                    ? 'An aggregate that is a Doctrine ORM entity is loaded and saved through the entity manager, so a command handler on the aggregate needs no repository of its own.'
                    : 'Aggregates are not loaded or saved through Doctrine ORM: an aggregate that is an ORM entity needs a repository of its own.',
                configuration: 'DbalConfiguration::createWithDefaults()->withDoctrineORMRepositories(isORMEnabled: true)',
            ),
        ];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
