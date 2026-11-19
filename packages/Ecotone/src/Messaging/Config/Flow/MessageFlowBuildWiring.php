<?php

/*
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
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

final class MessageFlowBuildWiring implements DefinedObject
{
    /**
     * @param list<array{id: string, kind: string, label: string, attributes: array<string, mixed>}> $nodes
     * @param list<array{from: string, to: string, kind: string, attributes: array<string, mixed>}> $edges
     * @param array<string, string> $entryChannels
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $edges,
        public readonly array $entryChannels,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self(nodes: $data['nodes'], edges: $data['edges'], entryChannels: $data['entryChannels']);
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [json_encode(['nodes' => $this->nodes, 'edges' => $this->edges, 'entryChannels' => $this->entryChannels], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)], 'fromJson');
    }
}
