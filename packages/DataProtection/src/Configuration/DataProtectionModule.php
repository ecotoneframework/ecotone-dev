<?php

/**
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\DataProtection\Configuration;

use Ecotone\AnnotationFinder\AnnotatedMethod;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithEncryptionKey;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\DataProtection\MessageEncryption\MessageEncryptor;
use Ecotone\DataProtection\OutboundDecryptionChannelBuilder;
use Ecotone\DataProtection\OutboundEncryptionChannelBuilder;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use stdClass;

#[ModuleAnnotation]
final class DataProtectionModule extends NoExternalConfigurationModule
{
    final public const ENCRYPTOR_SERVICE_ID_FORMAT = 'ecotone.data-protection.encryptor.%s';
    final public const KEY_SERVICE_ID_FORMAT = 'ecotone.encryption.key.%s';

    /**
     * @param array<MessageEncryptionConfig> $encryptionConfigs
     */
    public function __construct(private array $encryptionConfigs)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $encryptionConfigs = self::resolveEncryptionConfigsFromAnnotatedClasses($annotationRegistrationService->findAnnotatedClasses(Sensitive::class), $interfaceToCallRegistry);
        $encryptionConfigs = self::resolveEncryptionConfigsFromAnnotatedMethods($annotationRegistrationService->findAnnotatedMethods(CommandHandler::class), $encryptionConfigs, $interfaceToCallRegistry);
        $encryptionConfigs = self::resolveEncryptionConfigsFromAnnotatedMethods($annotationRegistrationService->findAnnotatedMethods(EventHandler::class), $encryptionConfigs, $interfaceToCallRegistry);

        return new self($encryptionConfigs);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (! ExtensionObjectResolver::contains(DataProtectionConfiguration::class, $extensionObjects)) {
            return;
        }

        $this->verifyLicense($messagingConfiguration);

        Assert::isTrue(ExtensionObjectResolver::contains(JMSConverterConfiguration::class, $extensionObjects), sprintf('%s package require %s package to be enabled. Did you forget to define %s?', ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE, JMSConverterConfiguration::class));

        $dataProtectionConfiguration = ExtensionObjectResolver::resolveUnique(DataProtectionConfiguration::class, $extensionObjects, new stdClass());
        $channelProtectionConfigurations = ExtensionObjectResolver::resolve(ChannelProtectionConfiguration::class, $extensionObjects);

        foreach ($dataProtectionConfiguration->keys() as $encryptionKeyName => $key) {
            $messagingConfiguration->registerServiceDefinition(
                id: sprintf(self::KEY_SERVICE_ID_FORMAT, $encryptionKeyName),
                definition: new Definition(
                    Key::class,
                    [$key->saveToAsciiSafeString()],
                    'loadFromAsciiSafeString'
                )
            );
        }

        $channelEncryptorReferences = $messageEncryptorReferences = [];
        foreach ($channelProtectionConfigurations as $channelProtectionConfiguration) {
            Assert::isTrue($messagingConfiguration->isPollableChannel($channelProtectionConfiguration->channelName()), sprintf('`%s` channel must be pollable channel to use Data Protection.', $channelProtectionConfiguration->channelName()));

            $encryptionConfig = $channelProtectionConfiguration->messageEncryptionConfig();
            $messagingConfiguration->registerServiceDefinition(
                id: $id = sprintf(self::ENCRYPTOR_SERVICE_ID_FORMAT, $channelProtectionConfiguration->channelName()),
                definition: new Definition(
                    MessageEncryptor::class,
                    [
                        Reference::to(sprintf(self::KEY_SERVICE_ID_FORMAT, $encryptionConfig->encryptionKeyName($dataProtectionConfiguration))),
                        $encryptionConfig->isPayloadSensitive,
                        $encryptionConfig->sensitiveHeaders,
                    ],
                )
            );

            $channelEncryptorReferences[$channelProtectionConfiguration->channelName()] = Reference::to($id);
        }

        foreach ($this->encryptionConfigs as $messageClass => $encryptionConfig) {
            $messagingConfiguration->registerServiceDefinition(
                id: $id = sprintf(self::ENCRYPTOR_SERVICE_ID_FORMAT, $messageClass),
                definition: new Definition(
                    MessageEncryptor::class,
                    [
                        Reference::to(sprintf(self::KEY_SERVICE_ID_FORMAT, $encryptionConfig->encryptionKeyName($dataProtectionConfiguration))),
                        $encryptionConfig->isPayloadSensitive,
                        $encryptionConfig->sensitiveHeaders,
                    ],
                )
            );
            $messageEncryptorReferences[$messageClass] = Reference::to($id);
        }

        foreach (ExtensionObjectResolver::resolve(MessageChannelWithSerializationBuilder::class, $extensionObjects) as $pollableMessageChannel) {
            if (! $pollableMessageChannel->isPollable()) {
                continue;
            }

            $messagingConfiguration->registerChannelInterceptor(
                new OutboundEncryptionChannelBuilder(
                    relatedChannel: $pollableMessageChannel->getMessageChannelName(),
                    channelEncryptorReference: $channelEncryptorReferences[$pollableMessageChannel->getMessageChannelName()] ?? null,
                    messageEncryptorReferences: $messageEncryptorReferences,
                )
            );
            $messagingConfiguration->registerChannelInterceptor(
                new OutboundDecryptionChannelBuilder(
                    relatedChannel: $pollableMessageChannel->getMessageChannelName(),
                    channelEncryptionReference: $channelEncryptorReferences[$pollableMessageChannel->getMessageChannelName()] ?? null,
                    messageEncryptionReferences: $messageEncryptorReferences,
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

    private static function resolveEncryptionConfigsFromAnnotatedClasses(array $sensitiveMessages, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        $encryptionConfigs = [];
        foreach ($sensitiveMessages as $message) {
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::create($message));
            $encryptionKey = $classDefinition->findSingleClassAnnotation(Type::create(WithEncryptionKey::class))?->encryptionKey();
            $sensitiveHeaders = array_map(static fn (WithSensitiveHeader $annotation) => $annotation->header, $classDefinition->getClassAnnotations(Type::create(WithSensitiveHeader::class)) ?? []);

            $encryptionConfigs[$message] = new MessageEncryptionConfig(encryptionKey: $encryptionKey, isPayloadSensitive: true, sensitiveHeaders: $sensitiveHeaders);
        }

        return $encryptionConfigs;
    }

    private static function resolveEncryptionConfigsFromAnnotatedMethods(array $annotatedMethods, array $encryptionConfigs, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        /** @var AnnotatedMethod $method */
        foreach ($annotatedMethods as $method) {
            $methodDefinition = $interfaceToCallRegistry->getFor($method->getClassName(), $method->getMethodName());
            $payload = $methodDefinition->getFirstParameter();

            if (
                $payload->hasAnnotation(Header::class)
                || $payload->hasAnnotation(Headers::class)
                || $payload->hasAnnotation(Reference::class)
                || array_key_exists($payload->getTypeHint(), $encryptionConfigs)
            ) {
                continue;
            }

            $isPayloadSensitive = $payload->hasAnnotation(Sensitive::class);
            if (! $isPayloadSensitive) {
                continue;
            }

            $encryptionKey = $payload->findSingleAnnotation(Type::create(WithEncryptionKey::class))?->encryptionKey();
            $sensitiveHeaders = array_map(static fn (WithSensitiveHeader $annotation) => $annotation->header, $methodDefinition->getMethodAnnotationsOf(Type::create(WithSensitiveHeader::class)) ?? []);
            foreach ($methodDefinition->getInterfaceParameters() as $parameter) {
                if ($parameter->hasAnnotation(Header::class) && $parameter->hasAnnotation(Sensitive::class)) {
                    $sensitiveHeaders[] = $parameter->getName();
                }
            }

            $encryptionConfigs[$payload->getTypeHint()] = new MessageEncryptionConfig(encryptionKey: $encryptionKey, isPayloadSensitive: true, sensitiveHeaders: $sensitiveHeaders);
        }

        return $encryptionConfigs;
    }

    private function verifyLicense(Configuration $messagingConfiguration): void
    {
        if ($messagingConfiguration->isRunningForEnterpriseLicence()) {
            return;
        }

        throw LicensingException::create('Data Protection module is available only with Ecotone Enterprise Licence.');
    }
}
