<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\AsynchronousModule;
use Ecotone\Messaging\Config\Container\AttributeDeclaration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutorCompiler;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 */
final class GatheringHandlerCompiler
{
    public static function compile(MessagingContainerBuilder $builder, Definition $handler, ?InterfaceToCallReference $interface, string $endpointId, string $inputChannelName): Definition|Reference
    {
        if ($interface !== null && $builder->getInterfaceToCall($interface)->hasMethodAnnotation(Collected::class)) {
            $collected = $builder->getInterfaceToCall($interface)->getSingleAnnotation(Type::create(Collected::class));
            $reference = 'ecotone.collecting.handler.' . hash('sha256', $endpointId);
            $builder->register($reference . '.core', $handler);
            $builder->register($reference . '.enterprise', new Definition(CollectingMessageHandler::class, [
                new Reference($reference . '.core'),
                new Reference(GatheredGroupStore::class),
                new Reference(MessageBuilder::class),
                new Reference(ConversionService::class),
                $endpointId,
                substr($inputChannelName, 0, -strlen(AsynchronousModule::HANDLER_EXECUTION_CHANNEL_SUFFIX)),
                $collected->maxSize,
                AttributeExpressionExecutorCompiler::compile(
                    attributeWithExpression: $collected,
                    attributeDeclaration: new AttributeDeclaration(Collected::class, $interface->getClassName(), $interface->getMethodName()),
                    owner: $builder->getInterfaceToCall($interface),
                    targetParameterName: 'groupBy',
                ),
                new Definition(CollectedHandlerContext::class, [$interface->getClassName(), $interface->getMethodName(), $endpointId, is_string($collected->groupBy) ? $collected->groupBy : $interface->getClassName() . '::' . $interface->getMethodName() . ' groupBy closure']),
                $collected->maxWait?->toMilliseconds(),
                new Reference(EcotoneClockInterface::class),
                new Reference(ChannelResolver::class),
                CollectingPayloadType::for($collected),
                new Reference(GatheringConflictRedelivery::class),
            ]));
            return $builder->register($reference, LicenceDecider::prepareDefinition(className: MessageHandler::class, openCoreServiceReference: $reference . '.core', enterpriseServiceReference: $reference . '.enterprise'));
        }
        if ($interface !== null && $builder->getInterfaceToCall($interface)->hasMethodAnnotation(Gathered::class)) {
            $reference = 'ecotone.gathering.handler.' . $endpointId;
            $builder->register($reference . '.core', $handler);
            $builder->register($reference . '.enterprise', new Definition(GatheringMessageHandler::class, [
                new Reference($reference . '.core'),
                new Reference(GatheredGroupStore::class),
                new Reference(MessageBuilder::class),
                new Reference(ConversionService::class),
                new Reference(GatheringConflictRedelivery::class),
            ]));
            return $builder->register($reference, LicenceDecider::prepareDefinition(className: MessageHandler::class, openCoreServiceReference: $reference . '.core', enterpriseServiceReference: $reference . '.enterprise'));
        }

        return $handler;
    }
}
