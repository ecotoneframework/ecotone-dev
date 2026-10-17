<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

final class MethodSourceFacts
{
    /**
     * @param list<array{class: string, enclosingCall: string|null}> $instantiations
     * @param list<array{receiver: string|null, method: string, firstArgumentLiteral: string|null, firstArgumentClass: string|null}> $calls
     * @param list<string> $stringLiterals
     */
    public function __construct(
        public readonly array $instantiations,
        public readonly array $calls,
        public readonly array $stringLiterals,
    ) {
    }

    public static function none(): self
    {
        return new self([], [], []);
    }
}
