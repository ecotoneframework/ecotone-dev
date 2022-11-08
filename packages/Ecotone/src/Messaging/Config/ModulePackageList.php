<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config;

final class ModulePackageList
{
    public const CORE_PACKAGE = "core";
    public const ASYNCHRONOUS_PACKAGE = "asynchronous";
    public const AMQP_PACKAGE = "amqp";
    public const DBAL_PACKAGE = "dbal";
    public const EVENT_SOURCING_PACKAGE = "eventSourcing";
    public const JMS_CONVERTER_PACKAGE = "jmsConverter";

    public static function getModuleClassesForPackage(string $packageName): array
    {
        return match ($packageName) {
            ModulePackageList::CORE_PACKAGE => ModuleClassList::CORE_MODULES,
            ModulePackageList::ASYNCHRONOUS_PACKAGE => ModuleClassList::ASYNCHRONOUS_MODULE,
            ModulePackageList::AMQP_PACKAGE => ModuleClassList::AMQP_MODULES,
            ModulePackageList::DBAL_PACKAGE => ModuleClassList::DBAL_MODULES,
            ModulePackageList::EVENT_SOURCING_PACKAGE => ModuleClassList::EVENT_SOURCING_MODULES,
            ModulePackageList::JMS_CONVERTER_PACKAGE => ModuleClassList::JMS_CONVERTER_MODULES,
            default => throw ConfigurationException::create(sprintf("Given unknown package name %s. Available packages name are: %s", $packageName, implode(",", self::allPackages())))
        };
    }

    /**
     * @return string[]
     */
    public static function allPackages(): array
    {
        return [
            self::CORE_PACKAGE,
            self::ASYNCHRONOUS_PACKAGE,
            self::AMQP_PACKAGE,
            self::DBAL_PACKAGE,
            self::EVENT_SOURCING_PACKAGE,
            self::JMS_CONVERTER_PACKAGE
        ];
    }
}