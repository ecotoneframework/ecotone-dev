<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config;

/**
 * Simplified console command representation for external integrations.
 * Contains only the essential information needed for command registration.
 */
final class PreparedConsoleCommand
{
    /**
     * @param ConsoleCommandParameter[] $parameters
     */
    public function __construct(
        private readonly string $name,
        private readonly array $parameters
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return ConsoleCommandParameter[]
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public static function fromConfiguration(ConsoleCommandConfiguration $configuration): self
    {
        return new self(
            $configuration->getName(),
            $configuration->getParameters()
        );
    }
}
