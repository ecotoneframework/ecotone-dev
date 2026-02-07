<?php

/**
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\DataProtection\Configuration;

use Defuse\Crypto\Key;
use Ecotone\AnnotationFinder\AnnotatedMethod;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithEncryptionKey;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\DataProtection\Obfuscator\Obfuscator;
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
    /**
     * @param array<ObfuscatorConfig> $obfuscatorConfigs
     */
    public function __construct(private array $obfuscatorConfigs)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $obfuscatorConfigs = self::resolveObfuscatorConfigsFromAnnotatedClasses($annotationRegistrationService->findAnnotatedClasses(Sensitive::class), [], $interfaceToCallRegistry);
        $obfuscatorConfigs = self::resolveObfuscatorConfigsFromAnnotatedMethods($annotationRegistrationService->findAnnotatedMethods(CommandHandler::class), $obfuscatorConfigs, $interfaceToCallRegistry);
        $obfuscatorConfigs = self::resolveObfuscatorConfigsFromAnnotatedMethods($annotationRegistrationService->findAnnotatedMethods(EventHandler::class), $obfuscatorConfigs, $interfaceToCallRegistry);

        return new self($obfuscatorConfigs);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (! ExtensionObjectResolver::contains(DataProtectionConfiguration::class, $extensionObjects)) {
            return;
        }

        $this->verifyLicense($messagingConfiguration, $extensionObjects);

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
            Assert::isTrue($messagingConfiguration->isPollableChannel($channelProtectionConfiguration->channelName()), sprintf('`%s` channel must be pollable channel to use Data Protection.', $channelProtectionConfiguration->channelName()));

            $obfuscatorConfig = $channelProtectionConfiguration->obfuscatorConfig();
            $messagingConfiguration->registerServiceDefinition(
                id: $id = sprintf('ecotone.encryption.obfuscator.%s', $channelProtectionConfiguration->channelName()),
                definition: new Definition(
                    Obfuscator::class,
                    [
                        Reference::to(sprintf('ecotone.encryption.key.%s', $obfuscatorConfig->encryptionKeyName($dataProtectionConfiguration))),
                        $obfuscatorConfig->isPayloadSensitive,
                        $obfuscatorConfig->sensitiveHeaders,
                    ],
                )
            );

            $channelObfuscatorReferences[$channelProtectionConfiguration->channelName()] = Reference::to($id);
        }

        foreach ($this->obfuscatorConfigs as $messageClass => $obfuscatorConfig) {
            $messagingConfiguration->registerServiceDefinition(
                id: $id = sprintf('ecotone.encryption.obfuscator.%s', $messageClass),
                definition: new Definition(
                    Obfuscator::class,
                    [
                        Reference::to(sprintf('ecotone.encryption.key.%s', $obfuscatorConfig->encryptionKeyName($dataProtectionConfiguration))),
                        $obfuscatorConfig->isPayloadSensitive,
                        $obfuscatorConfig->sensitiveHeaders,
                    ],
                )
            );
            $messageObfuscatorReferences[$messageClass] = Reference::to($id);
        }

        foreach (ExtensionObjectResolver::resolve(MessageChannelWithSerializationBuilder::class, $extensionObjects) as $pollableMessageChannel) {
            if (! $pollableMessageChannel->isPollable()) {
                continue;
            }

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

    private static function resolveObfuscatorConfigsFromAnnotatedClasses(array $sensitiveMessages, array $obfuscatorConfigs, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        foreach ($sensitiveMessages as $message) {
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::create($message));
            $encryptionKey = $classDefinition->findSingleClassAnnotation(Type::create(WithEncryptionKey::class))?->encryptionKey();
            $sensitiveHeaders = array_map(static fn (WithSensitiveHeader $annotation) => $annotation->header, $classDefinition->getClassAnnotations(Type::create(WithSensitiveHeader::class)) ?? []);

            $obfuscatorConfigs[$message] = new ObfuscatorConfig(encryptionKey: $encryptionKey, isPayloadSensitive: true, sensitiveHeaders: $sensitiveHeaders);
        }

        return $obfuscatorConfigs;
    }

    private static function resolveObfuscatorConfigsFromAnnotatedMethods(array $annotatedMethods, array $obfuscatorConfigs, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        /** @var AnnotatedMethod $method */
        foreach ($annotatedMethods as $method) {
            $methodDefinition = $interfaceToCallRegistry->getFor($method->getClassName(), $method->getMethodName());
            $payload = $methodDefinition->getFirstParameter();

            if (
                $payload->hasAnnotation(Header::class)
                || $payload->hasAnnotation(Headers::class)
                || $payload->hasAnnotation(Reference::class)
                || array_key_exists($payload->getTypeHint(), $obfuscatorConfigs)
            ) {
                continue;
            }

            $isPayloadSensitive = $payload->hasAnnotation(Sensitive::class) || $methodDefinition->hasAnnotation(Sensitive::class);
            if (! $isPayloadSensitive) {
                continue;
            }

            $encryptionKey = $payload->findSingleAnnotation(Type::create(WithEncryptionKey::class))?->encryptionKey();
            if ($encryptionKey === null) {
                $encryptionKey = $methodDefinition->findSingleMethodAnnotation(Type::create(WithEncryptionKey::class))?->encryptionKey();
            }
            $sensitiveHeaders = array_map(static fn (WithSensitiveHeader $annotation) => $annotation->header, $methodDefinition->getMethodAnnotationsOf(Type::create(WithSensitiveHeader::class)) ?? []);

            foreach ($methodDefinition->getInterfaceParameters() as $parameter) {
                if ($parameter->hasAnnotation(Header::class) && $parameter->hasAnnotation(Sensitive::class)) {
                    $sensitiveHeaders[] = $parameter->getName();
                }
            }

            $obfuscatorConfigs[$payload->getTypeHint()] = new ObfuscatorConfig($encryptionKey, $isPayloadSensitive, $sensitiveHeaders);
        }

        return $obfuscatorConfigs;
    }

    private function verifyLicense(Configuration $messagingConfiguration, array $extensionObjects): void
    {
        if ($messagingConfiguration->isRunningForEnterpriseLicence()) {
            return;
        }

        throw LicensingException::create('Data Protection module is available only with Ecotone Enterprise Licence.');
    }
}
