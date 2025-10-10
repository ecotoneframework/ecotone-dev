<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbaBusinessMethod;

use Ecotone\AnnotationFinder\AnnotatedMethod;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Dbal\Attribute\DbalQuery;
use Ecotone\Dbal\Attribute\DbalWrite;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderValueBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\AllHeadersBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\Assert;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class DbaBusinessMethodModule implements AnnotationModule
{
    private const BUSINESS_METHOD_HANDLER_REQUEST_CHANNEL_PREFIX = 'ecotone.dbal.business_method.invoke.';

    /**
     * @param GatewayProxyBuilder[] $dbalBusinessMethodGateways
     * @param string[] $connectionReferences
     */
    private function __construct(
        private array $dbalBusinessMethodGateways,
        private array $connectionReferences
    ) {
    }

    private static function getWriteRequestChannelName(string $connectionReferenceName): string
    {
        return self::BUSINESS_METHOD_HANDLER_REQUEST_CHANNEL_PREFIX . $connectionReferenceName . '_write';
    }

    private static function getQueryRequestChannelName(string $connectionReferenceName): string
    {
        return self::BUSINESS_METHOD_HANDLER_REQUEST_CHANNEL_PREFIX . $connectionReferenceName . '_query';
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $connectionReferences = [];
        $gatewayProxyBuilders = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(DbalWrite::class) as $businessMethod) {
            /** @var DbalWrite $attribute */
            $attribute = $businessMethod->getAnnotationForMethod();

            $gateway = self::getGateway($businessMethod, $attribute);

            $interface = $interfaceToCallRegistry->getFor($businessMethod->getClassName(), $businessMethod->getMethodName());

            Assert::isFalse($interface->getReturnType()->isAnything(), "{$interface} must have return type defined.");
            Assert::isTrue($interface->getReturnType()->isVoid() || $interface->getReturnType()->isInteger(), "{$interface} write business method must return void or integer. Did you meant to use DbalQueryBusinessMethod?");

            $gatewayProxyBuilders[] = $gateway->withParameterConverters(self::getParameterConverters($attribute, $interface));
            $connectionReferences[] = $attribute->getConnectionReferenceName();
        }

        foreach ($annotationRegistrationService->findAnnotatedMethods(DbalQuery::class) as $businessMethod) {
            /** @var DbalQuery $attribute */
            $attribute = $businessMethod->getAnnotationForMethod();

            $gateway = self::getGateway($businessMethod, $attribute);

            $interface = $interfaceToCallRegistry->getFor($businessMethod->getClassName(), $businessMethod->getMethodName());

            if ($attribute->getReplyContentType()) {
                $gateway = $gateway->withReplyContentType($attribute->getReplyContentType());
            }

            Assert::isFalse($interface->getReturnType()->isAnything(), "{$interface} must have return type defined.");
            Assert::isFalse($interface->getReturnType()->isVoid(), "{$interface} query business method must have return type defined. Did you meant to use DbalWriteBusinessMethod?");

            $gatewayProxyBuilders[] = $gateway->withParameterConverters(array_merge(
                self::getParameterConverters($attribute, $interface),
                [
                    GatewayHeaderValueBuilder::create(
                        DbalBusinessMethodHandler::HEADER_FETCH_MODE,
                        $attribute->getFetchMode()
                    ),
                    GatewayHeaderValueBuilder::create(
                        DbalBusinessMethodHandler::IS_INTERFACE_NULLABLE,
                        $interface->canItReturnNull()
                    ),
                ]
            ));

            $connectionReferences[] = $attribute->getConnectionReferenceName();
        }

        return new self($gatewayProxyBuilders, array_unique($connectionReferences));
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        foreach ($this->dbalBusinessMethodGateways as $gatewayProxyBuilder) {
            $messagingConfiguration->registerGatewayBuilder($gatewayProxyBuilder);
        }

        foreach ($this->connectionReferences as $connectionReference) {
            $referenceName = DbalBusinessMethodHandler::class . '_' . $connectionReference;
            $messagingConfiguration->registerServiceDefinition(
                Reference::to($referenceName),
                new Definition(
                    DbalBusinessMethodHandler::class,
                    [
                        Reference::to($connectionReference),
                        Reference::to(ConversionService::REFERENCE_NAME),
                        Reference::to(ExpressionEvaluationService::REFERENCE),
                    ]
                )
            );

            $messagingConfiguration->registerMessageHandler(
                ServiceActivatorBuilder::create(
                    $referenceName,
                    $interfaceToCallRegistry->getFor(DbalBusinessMethodHandler::class, 'executeWrite')
                )
                    ->withInputChannelName(self::getWriteRequestChannelName($connectionReference))
                    ->withMethodParameterConverters([
                        HeaderBuilder::create('sql', DbalBusinessMethodHandler::SQL_HEADER),
                        AllHeadersBuilder::createWith('headers'),
                    ])
            );

            $messagingConfiguration->registerMessageHandler(
                ServiceActivatorBuilder::create(
                    $referenceName,
                    $interfaceToCallRegistry->getFor(DbalBusinessMethodHandler::class, 'executeQuery')
                )
                    ->withInputChannelName(self::getQueryRequestChannelName($connectionReference))
                    ->withMethodParameterConverters([
                        HeaderBuilder::create('sql', DbalBusinessMethodHandler::SQL_HEADER),
                        HeaderBuilder::create('isInterfaceNullable', DbalBusinessMethodHandler::IS_INTERFACE_NULLABLE),
                        HeaderBuilder::create('fetchMode', DbalBusinessMethodHandler::HEADER_FETCH_MODE),
                        AllHeadersBuilder::createWith('headers'),
                    ])
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return false;
    }

    private static function getGateway(AnnotatedMethod $businessMethod, DbalWrite|DbalQuery $attribute): GatewayProxyBuilder
    {
        return GatewayProxyBuilder::create(
            AnnotatedDefinitionReference::getReferenceFor($businessMethod),
            $businessMethod->getClassName(),
            $businessMethod->getMethodName(),
            $attribute instanceof DbalWrite
                ? self::getWriteRequestChannelName($attribute->getConnectionReferenceName())
                : self::getQueryRequestChannelName($attribute->getConnectionReferenceName())
        );
    }

    private static function getParameterConverters(DbalWrite|DbalQuery $attribute, InterfaceToCall $interface): array
    {
        $parameterConverters = [
            GatewayHeaderValueBuilder::create(
                DbalBusinessMethodHandler::SQL_HEADER,
                $attribute->getSql()
            ),
        ];

        /** @var DbalParameter $dbalParameterAttribute */
        foreach (array_merge(
            $interface->getClassAnnotationOf(Type::object(DbalParameter::class)),
            $interface->getMethodAnnotationsOf(Type::object(DbalParameter::class))
        ) as $dbalParameterAttribute) {
            Assert::isFalse(isset($parameterConverters[$dbalParameterAttribute->getName()]), "Parameter {$dbalParameterAttribute->getName()} is defined twice in {$dbalParameterAttribute->getName()}");
            Assert::isTrue($dbalParameterAttribute->getName() !== null, "Parameter name must be defined in {$dbalParameterAttribute->getName()}");
            Assert::isTrue($dbalParameterAttribute->getExpression() !== null, "Parameter {$dbalParameterAttribute->getName()} must have expression defined in {$dbalParameterAttribute->getName()}");

            $parameterConverters[$dbalParameterAttribute->getName()] = GatewayHeaderValueBuilder::create(
                DbalBusinessMethodHandler::HEADER_PARAMETER_TYPE_PREFIX . $dbalParameterAttribute->getName(),
                $dbalParameterAttribute
            );
        }

        foreach ($interface->getInterfaceParameters() as $interfaceParameter) {
            if ($interfaceParameter->hasAnnotation(DbalParameter::class)) {
                $annotationsOfType = $interfaceParameter->getAnnotationsOfType(DbalParameter::class);
                Assert::isTrue(count($annotationsOfType) === 1, "Only one DbalParameter annotation can be used on {$interfaceParameter}");
                /** @var DbalParameter $dbalParameterAttribute */
                $dbalParameterAttribute = $annotationsOfType[0];

                Assert::isFalse(isset($parameterConverters[$dbalParameterAttribute->getName()]), "Parameter {$dbalParameterAttribute->getName()} is defined twice");
                $parameterConverters[] = GatewayHeaderValueBuilder::create(
                    DbalBusinessMethodHandler::HEADER_PARAMETER_TYPE_PREFIX . $interfaceParameter->getName(),
                    $dbalParameterAttribute
                );
            }

            $parameterConverters[] = GatewayHeaderBuilder::create(
                $interfaceParameter->getName(),
                DbalBusinessMethodHandler::HEADER_PARAMETER_VALUE_PREFIX . $interfaceParameter->getName()
            );
        }

        return $parameterConverters;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
