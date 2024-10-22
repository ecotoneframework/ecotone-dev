<?php

namespace Ecotone\Modelling\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;

/**
 * licence Apache-2.0
 */
class MessageBusChannel
{
    public const COMMAND_CHANNEL_NAME_BY_OBJECT = 'ecotone.modelling.bus.command_by_object';
    public const COMMAND_CHANNEL_NAME_BY_NAME   = 'ecotone.modelling.bus.command_by_name';

    public const QUERY_CHANNEL_NAME_BY_OBJECT = 'ecotone.modelling.bus.query_by_object';
    public const QUERY_CHANNEL_NAME_BY_NAME   = 'ecotone.modelling.bus.query_by_name';

    public const EVENT_CHANNEL_NAME_BY_OBJECT = 'ecotone.modelling.bus.event_by_object';
    public const EVENT_CHANNEL_NAME_BY_NAME   = 'ecotone.modelling.bus.event_by_name';
}
