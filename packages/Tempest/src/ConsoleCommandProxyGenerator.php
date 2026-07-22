<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use const DIRECTORY_SEPARATOR;

use Ecotone\Messaging\Config\ConsoleCommandConfiguration;

/**
 * licence Apache-2.0
 */
final class ConsoleCommandProxyGenerator
{
    private const HASH_MARKER_FILE = '.ecotone_hash';

    /**
     * @param ConsoleCommandConfiguration[] $commandConfigurations
     * @return string[] absolute paths to the generated proxy files
     */
    public function generate(array $commandConfigurations, string $outputDirectory, ?string $configHash = null): array
    {
        if (! is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0777, true);
        }

        $generatedFiles = $this->resolveExpectedFilePaths($commandConfigurations, $outputDirectory);

        if ($configHash !== null && $this->isHashUnchanged($outputDirectory, $configHash, $generatedFiles)) {
            return $generatedFiles;
        }

        $generatedFiles = [];

        foreach ($commandConfigurations as $configuration) {
            $generatedFiles[] = $this->writeProxyFile($configuration, $outputDirectory);
        }

        if ($configHash !== null) {
            file_put_contents($outputDirectory . DIRECTORY_SEPARATOR . self::HASH_MARKER_FILE, $configHash);
        }

        return $generatedFiles;
    }

    private function resolveExpectedFilePaths(array $commandConfigurations, string $outputDirectory): array
    {
        $files = [];
        foreach ($commandConfigurations as $configuration) {
            $className = $this->buildClassName($configuration->getName());
            $files[] = $outputDirectory . DIRECTORY_SEPARATOR . $className . '.php';
        }
        return $files;
    }

    private function isHashUnchanged(string $outputDirectory, string $configHash, array $expectedFiles): bool
    {
        $markerFile = $outputDirectory . DIRECTORY_SEPARATOR . self::HASH_MARKER_FILE;

        if (! file_exists($markerFile)) {
            return false;
        }

        if (file_get_contents($markerFile) !== $configHash) {
            return false;
        }

        foreach ($expectedFiles as $file) {
            if (! file_exists($file)) {
                return false;
            }
        }

        return true;
    }

    private function writeProxyFile(ConsoleCommandConfiguration $configuration, string $outputDirectory): string
    {
        $commandName = $configuration->getName();
        $className = $this->buildClassName($commandName);
        $filePath = $outputDirectory . DIRECTORY_SEPARATOR . $className . '.php';

        file_put_contents($filePath, $this->buildProxyClassCode($className, $commandName, $configuration->getDescription(), ...$this->parameterNamesByKind($configuration)));

        return $filePath;
    }

    /**
     * @return array{0: string[], 1: string[]} positional argument names and option names
     */
    private function parameterNamesByKind(ConsoleCommandConfiguration $configuration): array
    {
        $argumentNames = [];
        $optionNames = [];

        foreach ($configuration->getParameters() as $parameter) {
            if ($parameter->isOption()) {
                $optionNames[] = $parameter->getName();
            } else {
                $argumentNames[] = $parameter->getName();
            }
        }

        return [$argumentNames, $optionNames];
    }

    private function buildProxyClassCode(string $className, string $commandName, string $description, array $argumentNames, array $optionNames): string
    {
        $escapedCommandName = addslashes($commandName);
        $escapedDescription = addslashes($description);
        $exportedArgumentNames = var_export($argumentNames, true);
        $exportedOptionNames = var_export($optionNames, true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Ecotone\Tempest\Generated;

            use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
            use Ecotone\Messaging\Config\ConsoleCommandResultSet;
            use Ecotone\Messaging\Console\ConsoleWriter;
            use Ecotone\Messaging\Console\DelegatingConsoleWriter;
            use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
            use Ecotone\Tempest\TempestConsoleWriter;
            use Tempest\Console\Console;
            use Tempest\Console\ConsoleCommand;
            use Tempest\Console\ExitCode;
            use Tempest\Console\Input\ConsoleArgumentBag;
            use Tempest\Container\Inject;

            /**
             * licence Apache-2.0
             */
            final class {$className}
            {
                #[Inject]
                private readonly ConfiguredMessagingSystem \$messagingSystem;

                #[Inject]
                private readonly ConsoleArgumentBag \$argumentBag;

                #[Inject]
                private readonly Console \$console;

                #[ConsoleCommand(name: '{$escapedCommandName}', description: '{$escapedDescription}', allowDynamicArguments: true)]
                public function __invoke(): ExitCode
                {
                    \$runner = \$this->messagingSystem->getGatewayByName(ConsoleCommandRunner::class);
                    \$allArgs = \Ecotone\Tempest\ConsoleProxyArguments::map(\$this->argumentBag->all(), {$exportedArgumentNames}, {$exportedOptionNames});
                    /** @var DelegatingConsoleWriter \$delegatingWriter */
                    \$delegatingWriter = \$this->messagingSystem->getServiceFromContainer(ConsoleWriter::class);
                    \$writer = new TempestConsoleWriter(\$this->console);
                    \$result = \$delegatingWriter->executeWith(
                        \$writer,
                        fn () => \$runner->execute('{$escapedCommandName}', \$allArgs)
                    );
                    if (\$result instanceof ConsoleCommandResultSet) {
                        \$writer->table(\$result->getColumnHeaders(), \$result->getRows());
                    }
                    return ExitCode::SUCCESS;
                }
            }
            PHP;
    }

    private function buildClassName(string $commandName): string
    {
        return 'EcotoneConsoleCommand_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $commandName);
    }
}
