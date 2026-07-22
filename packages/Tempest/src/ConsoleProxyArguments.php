<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Tempest\Console\Input\ConsoleInputArgument;

/**
 * Maps Tempest CLI input onto the parameter names an Ecotone console command
 * declares. Tempest kebab-cases named arguments (consumerName -> consumer-name)
 * and leaves positional arguments unnamed; Ecotone's ConsoleCommandRunner
 * expects the declared camelCase names.
 *
 * licence Apache-2.0
 */
final class ConsoleProxyArguments
{
    /**
     * @param ConsoleInputArgument[] $consoleArguments
     * @param string[] $argumentNames declared positional argument names, in declaration order
     * @param string[] $optionNames declared option names
     * @return array<string, mixed>
     */
    public static function map(array $consoleArguments, array $argumentNames, array $optionNames = []): array
    {
        $declaredByKebabName = [];
        foreach ([...$argumentNames, ...$optionNames] as $parameterName) {
            $declaredByKebabName[self::kebabCase($parameterName)] = $parameterName;
        }

        $mapped = [];
        $positionalValues = [];

        foreach ($consoleArguments as $argument) {
            if ($argument->name === null) {
                $positionalValues[] = $argument->value;

                continue;
            }

            $name = ltrim($argument->name, '-');
            $mapped[$declaredByKebabName[self::kebabCase($name)] ?? self::camelCase($name)] = $argument->value;
        }

        foreach ($argumentNames as $argumentName) {
            if ($positionalValues === []) {
                break;
            }

            if (array_key_exists($argumentName, $mapped)) {
                continue;
            }

            $mapped[$argumentName] = array_shift($positionalValues);
        }

        // Surplus positional values are dropped deliberately, not rejected:
        // when proxies run in-process (e.g. under PHPUnit) Tempest's argument
        // bag carries the host process argv, so unknown positionals are not
        // reliably user typos.
        return $mapped;
    }

    private static function kebabCase(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', $name));
    }

    private static function camelCase(string $name): string
    {
        return lcfirst(str_replace('-', '', ucwords($name, '-')));
    }
}
