<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration\PollableChannel\SendRetries;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

#[ModuleAnnotation]
final class PollableChannelSendRetriesModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $pollableMessageChannels = ExtensionObjectResolver::resolve(MessageChannelBuilder::class, $extensionObjects);

        foreach ($pollableMessageChannels as $pollableMessageChannel) {
            $messagingConfiguration->registerChannelInterceptor(
                new RetriesChannelInterceptorBuilder(
                    $pollableMessageChannel->getMessageChannelName(),
                    RetryTemplateBuilder::exponentialBackoff(1, 20)
                        ->maxRetryAttempts(2)
                        ->build()
                )
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof PollableChannelConfiguration
            || ($extensionObject instanceof MessageChannelBuilder && $extensionObject->isPollable());
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}