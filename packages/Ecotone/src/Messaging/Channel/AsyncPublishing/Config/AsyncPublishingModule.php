<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\AsyncPublishing\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingWaiterInterceptor;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
final class AsyncPublishingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $messagingConfiguration->registerServiceDefinition(
            AsyncPublishingWaiterInterceptor::class,
            new Definition(AsyncPublishingWaiterInterceptor::class, [new Reference(AsyncPublishingRegistry::class)])
        );
        $messagingConfiguration->registerAroundMethodInterceptor(
            AroundInterceptorBuilder::create(
                AsyncPublishingWaiterInterceptor::class,
                $interfaceToCallRegistry->getFor(AsyncPublishingWaiterInterceptor::class, 'await'),
                Precedence::ASYNC_PUBLISHING_AWAIT_PRECEDENCE,
                CommandBus::class . '||' . AsynchronousRunningEndpoint::class,
            )
        );
    }

    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
