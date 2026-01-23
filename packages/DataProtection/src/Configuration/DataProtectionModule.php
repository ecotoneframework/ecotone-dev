<?php

/**
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\DataProtection\Configuration;

use Defuse\Crypto\Key;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\DataProtection\Attribute\UsingSensitiveData;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\DataProtection\Attribute\WithSensitiveHeaders;
use Ecotone\DataProtection\Obfuscator\Obfuscator;
use Ecotone\DataProtection\OutboundDecryptionChannelBuilder;
use Ecotone\DataProtection\OutboundEncryptionChannelBuilder;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use stdClass;

#[ModuleAnnotation]
final class DataProtectionModule extends NoExternalConfigurationModule
{
    /**
     * @param array<ObfuscatorConfig> $messageObfuscators
     */
    public function __construct(
        private array $messageObfuscators,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $messageObfuscators = [];

        $messagesUsingSensitiveData = $annotationRegistrationService->findAnnotatedClasses(UsingSensitiveData::class);

        foreach ($messagesUsingSensitiveData as $messageUsingSensitiveData) {
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::create($messageUsingSensitiveData));
            $usingSensitiveDataAttribute = $classDefinition->getSingleClassAnnotation(Type::create(UsingSensitiveData::class));

            $sensitiveHeaders = $classDefinition->findSingleClassAnnotation(Type::create(WithSensitiveHeaders::class))?->headers ?? [];
            foreach ($classDefinition->getClassAnnotations(Type::create(WithSensitiveHeader::class)) as $sensitiveHeader) {
                $sensitiveHeaders[] = $sensitiveHeader->header;
            }

            $messageObfuscators[$messageUsingSensitiveData] = new ObfuscatorConfig($usingSensitiveDataAttribute->encryptionKeyName(), $sensitiveHeaders);
        }

        $endpointsUsingSensitiveData = $annotationRegistrationService->findAnnotatedMethods(UsingSensitiveData::class);

        foreach ($endpointsUsingSensitiveData as $endpointUsingSensitiveData) {
            $methodDefinition = $interfaceToCallRegistry->getFor($endpointUsingSensitiveData->getClassName(), $endpointUsingSensitiveData->getMethodName());

            if (! $methodDefinition->hasAnnotation(CommandHandler::class) && ! $methodDefinition->hasAnnotation(EventHandler::class)) {
                Assert::isTrue(false, 'Only CommandHandler and EventHandler can be annotated with UsingSensitiveData.');
            }

            $message = $methodDefinition->getFirstParameter();

            if (array_key_exists($message->getTypeHint(), $messageObfuscators)) {
                continue;
            }

            if ($message->hasAnnotation(Payload::class)) {
                $registerObfuscatorFor = $message->getTypeHint();
            } else {
                $registerObfuscatorFor = self::resolveEndpointId($methodDefinition);
            }

            $usingSensitiveDataAttribute = $methodDefinition->getSingleMethodAnnotationOf(Type::create(UsingSensitiveData::class));
            $sensitiveHeaders = $methodDefinition->findSingleMethodAnnotation(Type::create(WithSensitiveHeaders::class))?->headers ?? [];
            foreach ($methodDefinition->getMethodAnnotationsOf(Type::create(WithSensitiveHeader::class)) as $sensitiveHeader) {
                $sensitiveHeaders[] = $sensitiveHeader->header;
            }

            $messageObfuscators[$registerObfuscatorFor] = new ObfuscatorConfig($usingSensitiveDataAttribute->encryptionKeyName(), $sensitiveHeaders);
        }

        return new self($messageObfuscators);
    }

    private static function resolveEndpointId(InterfaceToCall $methodDefinition): string
    {
        if ($methodDefinition->hasAnnotation(CommandHandler::class)) {
            return $methodDefinition->getSingleMethodAnnotationOf(Type::create(CommandHandler::class))->getEndpointId();
        }

        return $methodDefinition->getSingleMethodAnnotationOf(Type::create(EventHandler::class))->getEndpointId();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        Assert::isTrue(ExtensionObjectResolver::contains(DataProtectionConfiguration::class, $extensionObjects), sprintf('%s was not found.', DataProtectionConfiguration::class));
        Assert::isTrue(ExtensionObjectResolver::contains(JMSConverterConfiguration::class, $extensionObjects), sprintf('%s package require %s package to be enabled. Did you forget to define %s?', ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE, JMSConverterConfiguration::class));

        $dataProtectionConfiguration = ExtensionObjectResolver::resolveUnique(DataProtectionConfiguration::class, $extensionObjects, new stdClass());
        $channelProtectionConfigurations = ExtensionObjectResolver::resolve(ChannelProtectionConfiguration::class, $extensionObjects);

        foreach ($dataProtectionConfiguration->keys() as $encryptionKeyName => $key) {
            $messagingConfiguration->registerServiceDefinition(
                id: sprintf('ecotone.encryption.key.%s', $encryptionKeyName),
                definition: new Definition(
                    Key::class,
                    [$key->saveToAsciiSafeString()],
                    'loadFromAsciiSafeString'
                )
            );
        }

        $channelObfuscatorReferences = $messageObfuscatorReferences = [];
        foreach ($channelProtectionConfigurations as $channelProtectionConfiguration) {
            $obfuscatorConfig = $channelProtectionConfiguration->obfuscatorConfig();
            $messagingConfiguration->registerServiceDefinition(
                id: $id = sprintf('ecotone.encryption.obfuscator.%s', $channelProtectionConfiguration->channelName()),
                definition: new Definition(
                    Obfuscator::class,
                    [
                        Reference::to(sprintf('ecotone.encryption.key.%s', $obfuscatorConfig->encryptionKeyName($dataProtectionConfiguration))),
                        $obfuscatorConfig->sensitiveHeaders,
                    ],
                )
            );

            $channelObfuscatorReferences[$channelProtectionConfiguration->channelName()] = Reference::to($id);
        }

        foreach($this->messageObfuscators as $messageClass => $obfuscatorConfig) {
            $messagingConfiguration->registerServiceDefinition(
                id: $id = sprintf('ecotone.encryption.obfuscator.%s', $messageClass),
                definition: new Definition(
                    Obfuscator::class,
                    [
                        Reference::to(sprintf('ecotone.encryption.key.%s', $obfuscatorConfig->encryptionKeyName($dataProtectionConfiguration))),
                        $obfuscatorConfig->sensitiveHeaders,
                    ],
                )
            );
            $messageObfuscatorReferences[$messageClass] = Reference::to($id);
        }

        foreach (ExtensionObjectResolver::resolve(MessageChannelWithSerializationBuilder::class, $extensionObjects) as $pollableMessageChannel) {
            $messagingConfiguration->registerChannelInterceptor(
                new OutboundEncryptionChannelBuilder(
                    relatedChannel: $pollableMessageChannel->getMessageChannelName(),
                    channelObfuscatorReference: $channelObfuscatorReferences[$pollableMessageChannel->getMessageChannelName()] ?? null,
                    messageObfuscatorReferences: $messageObfuscatorReferences,
                )
            );
            $messagingConfiguration->registerChannelInterceptor(
                new OutboundDecryptionChannelBuilder(
                    relatedChannel: $pollableMessageChannel->getMessageChannelName(),
                    channelObfuscatorReference: $channelObfuscatorReferences[$pollableMessageChannel->getMessageChannelName()] ?? null,
                    messageObfuscatorReferences: $messageObfuscatorReferences,
                )
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof DataProtectionConfiguration
            || $extensionObject instanceof ChannelProtectionConfiguration
            || $extensionObject instanceof JMSConverterConfiguration
            || ($extensionObject instanceof MessageChannelWithSerializationBuilder && $extensionObject->isPollable())
        ;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DATA_PROTECTION_PACKAGE;
    }
}
