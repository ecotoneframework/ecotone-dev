<?php

namespace Ecotone\Modelling\Config\InstantRetry;

use Ecotone\AnnotationFinder\AnnotationFinder;
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

#[ModuleAnnotation]
final class InstantRetryModule implements AnnotationModule
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
        $configuration = ExtensionObjectResolver::resolveUnique(InstantRetryConfiguration::class, $extensionObjects, InstantRetryConfiguration::createWithDefaults());

        if ($configuration->isEnabledForCommandBus()) {
            $this->registerInterceptor($messagingConfiguration, $interfaceToCallRegistry, $configuration->getCommandBusRetryTimes(), $configuration->getCommandBuExceptions(), CommandBus::class);
        }
        if ($configuration->isEnabledForAsynchronousEndpoints()) {
            $this->registerInterceptor($messagingConfiguration, $interfaceToCallRegistry, $configuration->getAsynchronousRetryTimes(), $configuration->getAsynchronousExceptions(), AsynchronousRunningEndpoint::class);
        }
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof InstantRetryConfiguration;
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
        return ModulePackageList::CORE_PACKAGE;
    }

    private function registerInterceptor(Configuration $messagingConfiguration, InterfaceToCallRegistry $interfaceToCallRegistry, int $retryAttempt, array $exceptions, string $pointcut): void
    {
        $messagingConfiguration
            ->registerAroundMethodInterceptor(
                AroundInterceptorReference::createWithDirectObjectAndResolveConverters(
                    $interfaceToCallRegistry,
                    new InstantRetryInterceptor($retryAttempt, $exceptions),
                    'retry',
                    Precedence::AROUND_INSTANT_RETRY_PRECEDENCE,
                    $pointcut
                )
            );
    }
}
