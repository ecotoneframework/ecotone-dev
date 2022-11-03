<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config;

interface ModulePackageList
{
    public const CORE_PACKAGE = "core";
    public const ASYNCHRONOUS_PACKAGE = "asynchronous";
    public const AMQP_PACKAGE = "amqp";
    public const DBAL_PACKAGE = "dbal";
    public const EVENT_SOURCING_PACKAGE = "eventSourcing";
    public const JMS_CONVERTER_PACKAGE = "jmsConverter";
}