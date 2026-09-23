<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Messaging\Config\ConfigurationException;

use function array_keys;
use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelDefinitionRegistry
{
    /**
     * @param array<class-string, array{tagNames: string[], handledEventClasses: class-string[]}> $rawDefinitions
     */
    private function __construct(
        private readonly array $rawDefinitions,
    ) {
    }

    public static function createEmpty(): self
    {
        return new self([]);
    }

    /**
     * @param array<class-string, array{tagNames: string[], handledEventClasses: class-string[]}> $rawDefinitions
     */
    public static function createWith(array $rawDefinitions): self
    {
        return new self($rawDefinitions);
    }

    public function has(string $className): bool
    {
        return isset($this->rawDefinitions[$className]);
    }

    public function get(string $className): DecisionModelDefinition
    {
        $raw = $this->rawDefinitions[$className] ?? throw ConfigurationException::create(sprintf(
            'No DecisionModel definition found for %s. Did you forget #[DecisionModel]?',
            $className
        ));

        return new DecisionModelDefinition($className, $raw['tagNames'], $raw['handledEventClasses']);
    }

    /**
     * @return class-string[]
     */
    public function classNames(): array
    {
        return array_keys($this->rawDefinitions);
    }
}
