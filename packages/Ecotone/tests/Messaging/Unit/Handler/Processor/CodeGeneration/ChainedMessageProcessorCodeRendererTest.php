<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Processor\CodeGeneration;

use Ecotone\Messaging\Handler\Processor\CodeGeneration\ChainedMessageProcessorCodeRenderer;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ChainedMessageProcessorCodeRendererTest extends TestCase
{
    public function test_it_renders_unrolled_chain_with_null_short_circuits(): void
    {
        $code = (new ChainedMessageProcessorCodeRenderer())->render('GeneratedChain', 3);

        self::assertSame(
            <<<'PHP'
                <?php

                if (class_exists('GeneratedChain', false)) {
                    return;
                }

                final class GeneratedChain implements \Ecotone\Messaging\Handler\MessageProcessor
                {
                    public function __construct(
                        private \Ecotone\Messaging\Handler\MessageProcessor $processor0,
                        private \Ecotone\Messaging\Handler\MessageProcessor $processor1,
                        private \Ecotone\Messaging\Handler\MessageProcessor $processor2,
                    ) {
                    }

                    public function process(\Ecotone\Messaging\Message $message): ?\Ecotone\Messaging\Message
                    {
                        $message = $this->processor0->process($message);
                        if ($message === null) {
                            return null;
                        }
                        $message = $this->processor1->process($message);
                        if ($message === null) {
                            return null;
                        }

                        return $this->processor2->process($message);
                    }
                }

                PHP,
            $code,
        );
    }
}
