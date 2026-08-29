<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing;

use function array_keys;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
final class StreamTableRegistry implements DefinedObject
{
    public const DEFAULT_STREAM = 'ecotone_event_stream';

    /**
     * @param array<string, array{table: string, connection: string}> $streams
     */
    private function __construct(
        private array $streams,
        private string $defaultConnectionReferenceName,
    ) {
    }

    /**
     * @param array<string, array{table: string, connection: string}> $streams
     */
    public static function createWith(array $streams, string $defaultConnectionReferenceName): self
    {
        return new self($streams, $defaultConnectionReferenceName);
    }

    public function tableFor(string $streamName): string
    {
        return $this->streams[$streamName]['table'] ?? $streamName;
    }

    public function connectionReferenceFor(string $streamName): string
    {
        return $this->streams[$streamName]['connection'] ?? $this->defaultConnectionReferenceName;
    }

    public function isDeclared(string $streamName): bool
    {
        return isset($this->streams[$streamName]);
    }

    /**
     * @return array<string>
     */
    public function declaredStreamNames(): array
    {
        return array_keys($this->streams);
    }

    /**
     * @return array<string> Table names declared for the given connection
     */
    public function tablesFor(string $connectionReferenceName): array
    {
        $tables = [];
        foreach ($this->streams as $stream) {
            if ($stream['connection'] === $connectionReferenceName && ! in_array($stream['table'], $tables, true)) {
                $tables[] = $stream['table'];
            }
        }

        return $tables;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->streams, $this->defaultConnectionReferenceName], 'createWith');
    }
}
