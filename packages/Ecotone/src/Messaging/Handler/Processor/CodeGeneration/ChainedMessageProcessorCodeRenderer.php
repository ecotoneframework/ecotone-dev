<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\CodeGeneration;

/**
 * licence Apache-2.0
 */
final class ChainedMessageProcessorCodeRenderer
{
    public function render(string $className, int $processorCount): string
    {
        $constructorParameters = [];
        for ($index = 0; $index < $processorCount; $index++) {
            $constructorParameters[] = "        private \Ecotone\Messaging\Handler\MessageProcessor \$processor{$index},";
        }
        $constructorParametersCode = implode("\n", $constructorParameters);

        $processLines = [];
        for ($index = 0; $index < $processorCount - 1; $index++) {
            $processLines[] = "        \$message = \$this->processor{$index}->process(\$message);";
            $processLines[] = '        if ($message === null) {';
            $processLines[] = '            return null;';
            $processLines[] = '        }';
        }
        $lastIndex = $processorCount - 1;
        $processLines[] = '';
        $processLines[] = "        return \$this->processor{$lastIndex}->process(\$message);";
        $processCode = implode("\n", $processLines);

        return <<<PHP
            <?php

            if (class_exists('{$className}', false)) {
                return;
            }

            final class {$className} implements \Ecotone\Messaging\Handler\MessageProcessor
            {
                public function __construct(
            {$constructorParametersCode}
                ) {
                }

                public function process(\Ecotone\Messaging\Message \$message): ?\Ecotone\Messaging\Message
                {
            {$processCode}
                }
            }

            PHP;
    }
}
