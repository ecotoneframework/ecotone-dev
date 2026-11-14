<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow\Version;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class FlowVersions
{
    public const FORMAT_VERSION = 1;

    public function __construct(public readonly string $location, private EcotoneClockInterface $clock)
    {
    }

    public function store(string $label, MessageFlowGraph $graph): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $label) !== 1) {
            throw InvalidArgumentException::create("Flow version label `{$label}` cannot be used, because the label becomes the file name of the stored version. Use letters, digits, dots, dashes and underscores only, starting with a letter or a digit, for example `1.4.0` or a commit hash.");
        }
        $file = $this->fileOf($label);
        if (is_file($file)) {
            throw InvalidArgumentException::create("Flow version `{$label}` is already stored in {$file}, and a stored version is not replaced. Store the current flow under another label, or delete that file first to store it again.");
        }
        if (! is_dir($this->location) && ! @mkdir($this->location, 0o775, true) && ! is_dir($this->location)) {
            throw InvalidArgumentException::create("Flow version `{$label}` could not be stored, because the location {$this->location} does not exist and could not be created. Create that directory, or point FlowVersionsConfiguration::createWithLocation() at a directory the application can write to.");
        }
        $content = json_encode(
            ['formatVersion' => self::FORMAT_VERSION, 'label' => $label, 'storedAt' => $this->clock->now()->format(DATE_ATOM), 'graph' => self::sorted($graph)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (@file_put_contents($file, $content . "\n") === false) {
            throw InvalidArgumentException::create("Flow version `{$label}` could not be written to {$file}. Make the location {$this->location} writable for the user running this command, or point FlowVersionsConfiguration::createWithLocation() at a directory that is.");
        }
    }

    /**
     * @return list<FlowVersion>
     */
    public function all(): array
    {
        $versions = array_map($this->read(...), glob($this->location . '/*.json') ?: []);
        usort($versions, fn (FlowVersion $first, FlowVersion $second): int => [$first->storedAt, $first->label] <=> [$second->storedAt, $second->label]);

        return $versions;
    }

    public function of(string $label): FlowVersion
    {
        foreach ($this->all() as $version) {
            if ($version->label === $label) {
                return $version;
            }
        }
        $stored = array_map(fn (FlowVersion $version): string => "`{$version->label}`", $this->all());

        throw InvalidArgumentException::create("Flow version `{$label}` is not stored in {$this->location}. Stored versions: " . ($stored === [] ? 'none' : implode(', ', $stored)) . ". Pass one of them, or store the current flow first with `ecotone:flow:store {$label}`.");
    }

    private function fileOf(string $label): string
    {
        return $this->location . '/' . $label . '.json';
    }

    private function read(string $file): FlowVersion
    {
        $repair = 'Delete the file, or store the flow again with `ecotone:flow:store <label>` after moving it away.';
        try {
            $stored = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw InvalidArgumentException::create("Flow version file {$file} is not valid JSON, so it cannot be read as a stored flow version. {$repair}");
        }
        if (! is_array($stored) || ! isset($stored['formatVersion'], $stored['label'], $stored['storedAt'], $stored['graph']['nodes'], $stored['graph']['edges'])) {
            throw InvalidArgumentException::create("Flow version file {$file} does not hold `formatVersion`, `label`, `storedAt` and `graph`, so it was not written by `ecotone:flow:store`. Keep only stored flow versions in {$this->location}. {$repair}");
        }
        if ($stored['formatVersion'] !== self::FORMAT_VERSION) {
            throw InvalidArgumentException::create("Flow version file {$file} has format version `{$stored['formatVersion']}`, but this Ecotone reads format version " . self::FORMAT_VERSION . '. Upgrade Ecotone to the version that stored it, or store the flow again with `ecotone:flow:store <label>`.');
        }

        return new FlowVersion((string) $stored['label'], (string) $stored['storedAt'], MessageFlowGraph::fromArray($stored['graph']));
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function sorted(MessageFlowGraph $graph): array
    {
        $nodes = $graph->nodes();
        usort($nodes, fn (FlowNode $first, FlowNode $second): int => strcmp($first->id, $second->id));
        $edges = $graph->edges();
        usort($edges, fn (FlowEdge $first, FlowEdge $second): int => strcmp($first->identity(), $second->identity()));

        return [
            'nodes' => array_map(fn (FlowNode $node): array => self::withSortedKeys($node->toArray()), $nodes),
            'edges' => array_map(fn (FlowEdge $edge): array => self::withSortedKeys($edge->toArray()), $edges),
        ];
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, mixed>
     */
    private static function withSortedKeys(array $element): array
    {
        $element['attributes'] = self::sortedAttributes($element['attributes']);

        return $element;
    }

    private static function sortedAttributes(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::sortedAttributes(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
