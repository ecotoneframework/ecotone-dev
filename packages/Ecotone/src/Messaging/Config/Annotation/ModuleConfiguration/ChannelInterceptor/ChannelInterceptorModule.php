<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ChannelInterceptor;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ChannelInterceptor;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MethodInterceptor\BeforeSendChannelInterceptorBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Support\LicensingException;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
class ChannelInterceptorModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param AnnotatedFinding[] $channelInterceptors
     */
    private function __construct(private array $channelInterceptors)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self($annotationRegistrationService->findAnnotatedMethods(ChannelInterceptor::class));
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (empty($this->channelInterceptors)) {
            return;
        }

        if (! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            $firstFinding = $this->channelInterceptors[0];
            throw LicensingException::create("ChannelInterceptor attribute on {$firstFinding->getClassName()}::{$firstFinding->getMethodName()} is available only with Ecotone Enterprise licence. See https://docs.ecotone.tech/enterprise for details.");
        }

        foreach ($this->channelInterceptors as $channelInterceptorFinding) {
            /** @var ChannelInterceptor $channelInterceptor */
            $channelInterceptor = $channelInterceptorFinding->getAnnotationForMethod();
            $interceptorInterface = $interfaceToCallRegistry->getFor($channelInterceptorFinding->getClassName(), $channelInterceptorFinding->getMethodName());

            $methodInterceptorBuilder = MethodInterceptorBuilder::create(
                new Reference(AnnotatedDefinitionReference::getReferenceFor($channelInterceptorFinding)),
                $interceptorInterface,
                $channelInterceptor->getPrecedence(),
                '',
                $channelInterceptor->isChangeHeaders(),
                ParameterConverterAnnotationFactory::createParameterConverters($interceptorInterface),
            );

            $messagingConfiguration->registerChannelInterceptor(new BeforeSendChannelInterceptorBuilder(
                $methodInterceptorBuilder,
                $channelInterceptor->getChannelName(),
                InterfaceToCallReference::fromInstance($interceptorInterface),
                [],
            ));
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
