<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Console;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Tempest\Console\ConsoleConfig;
use Tempest\Console\ConsoleCommand;
use Tempest\Container\Container;
use Tempest\Reflection\MethodReflector;

final class EcotoneConsoleCommandInitializer
{
    public static function registerCommands(Container $container, ConfiguredMessagingSystem $messagingSystem): void
    {
        if (!self::isConsoleMode()) {
            return;
        }

        if (!$container->has(ConsoleConfig::class)) {
            return;
        }

        $consoleConfig = $container->get(ConsoleConfig::class);

        foreach ($messagingSystem->getRegisteredConsoleCommands() as $commandConfig) {
            self::registerEcotoneCommand($container, $consoleConfig, $commandConfig, $messagingSystem);
        }
    }

    private static function isConsoleMode(): bool
    {
        return php_sapi_name() === 'cli';
    }

    private static function registerEcotoneCommand(
        Container $container,
        ConsoleConfig $consoleConfig,
        \Ecotone\Messaging\Config\PreparedConsoleCommand $commandConfig,
        ConfiguredMessagingSystem $messagingSystem
    ): void {
        $commandName = $commandConfig->getName();

        // Generate a dynamic class with proper method signature
        $className = self::generateCommandClass($commandConfig, $messagingSystem);

        // Register the generated class in container
        $container->singleton($className, fn() => new $className(
            $messagingSystem,
            $container->get(\Tempest\Console\Console::class)
        ));

        try {
            $reflectionMethod = new \ReflectionMethod($className, 'execute');
            $methodReflector = new MethodReflector($reflectionMethod);

            $tempestCommand = new ConsoleCommand(
                name: $commandName,
                description: "Ecotone command: {$commandName}",
                allowDynamicArguments: true // Allow dynamic arguments to handle unknown args
            );

            $consoleConfig->addCommand($methodReflector, $tempestCommand);
        } catch (\ReflectionException $e) {
            // Skip command registration if reflection fails
        }
    }

    private static function generateCommandClass(
        \Ecotone\Messaging\Config\PreparedConsoleCommand $commandConfig,
        ConfiguredMessagingSystem $messagingSystem
    ): string {
        $commandName = $commandConfig->getName();
        $className = 'EcotoneCommand' . str_replace([':', '-', '.'], '', ucwords($commandName, ':-.'));

        // Return existing class if already generated
        if (class_exists($className, false)) {
            return $className;
        }

        $parameters = $commandConfig->getParameters();
        $methodSignature = self::buildMethodSignature($parameters);
        $parameterMapping = self::buildParameterMapping($parameters, $commandName);

        $classCode = "
class {$className} {
    public function __construct(
        private \\Ecotone\\Messaging\\Config\\ConfiguredMessagingSystem \$messagingSystem,
        private \\Tempest\\Console\\Console \$console
    ) {}

    public function execute({$methodSignature}): int {
        \$ecotoneParameters = [];
        {$parameterMapping}

        try {
            \$result = \$this->messagingSystem->runConsoleCommand('{$commandName}', \$ecotoneParameters);

            if (\$result instanceof \\Ecotone\\Messaging\\Config\\ConsoleCommandResultSet) {
                \$this->displayTable(\$result->getColumnHeaders(), \$result->getRows());
                return 0;
            }

            if (\$result !== null) {
                \$this->console->writeln((string) \$result);
            }

            return 0;
        } catch (\\Throwable \$e) {
            \$this->console->error(\"Error executing command '{$commandName}': \" . \$e->getMessage());
            return 1;
        }
    }

    private function displayTable(array \$headers, array \$rows): void {
        if (!empty(\$headers)) {
            \$this->console->writeln(implode(' | ', \$headers));
            \$this->console->writeln(str_repeat('-', strlen(implode(' | ', \$headers))));
        }

        foreach (\$rows as \$row) {
            \$this->console->writeln(implode(' | ', \$row));
        }
    }
}";

        eval($classCode);
        return $className;
    }

    private static function buildMethodSignature(array $parameters): string
    {
        if (empty($parameters)) {
            return '';
        }

        $methodParams = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $hasDefault = $parameter->hasDefaultValue();
            $defaultValue = $hasDefault ? $parameter->getDefaultValue() : null;
            $isArray = $parameter->isArray();

            $paramString = '';

            // Add type hint based on available information
            if ($isArray) {
                $paramString .= 'array ';
            } else {
                // Use mixed type since we don't have specific type information
                $paramString .= 'mixed ';
            }

            $paramString .= '$' . $name;

            // Add default value if available
            if ($hasDefault) {
                $paramString .= ' = ' . var_export($defaultValue, true);
            }

            $methodParams[] = $paramString;
        }

        return implode(', ', $methodParams);
    }

    private static function buildParameterMapping(array $parameters, string $commandName): string
    {
        if (empty($parameters)) {
            return '// No parameters to map';
        }

        $mappingLines = [];

        foreach ($parameters as $parameter) {
            $paramName = $parameter->getName();
            $headerName = $parameter->getMessageHeaderName();

            $mappingLines[] = "        if (isset(\${$paramName})) {";
            $mappingLines[] = "            \$ecotoneParameters['{$headerName}'] = \${$paramName};";
            $mappingLines[] = "        }";
        }

        return implode("\n", $mappingLines);
    }
}
