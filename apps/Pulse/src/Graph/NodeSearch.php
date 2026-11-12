<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class NodeSearch
{
    private const NAMED_BY = ['class', 'method', 'endpointId', 'routingKey', 'routingKeys', 'name', 'interface'];

    /**
     * @param list<array{service: string, nodeId: string, kind: string, kindLabel: string, family: string, label: string, fullLabel: string, exact: bool, named: bool}> $results
     */
    private function __construct(public array $results)
    {
    }

    /**
     * @param list<ServiceFlow> $flows
     */
    public static function of(array $flows, string $query): self
    {
        $words = array_values(array_filter(preg_split('/\\s+/', trim($query)) ?: []));
        $results = [];
        foreach ($words === [] ? [] : $flows as $flow) {
            foreach ($flow->graph->nodes() as $node) {
                $label = ShortLabel::of($node->label);
                $exact = mb_strtolower($label) === mb_strtolower(trim($query)) || mb_strtolower($node->label) === mb_strtolower(trim($query));
                $named = [$node->label, $node->id];
                foreach (self::NAMED_BY as $attribute) {
                    array_push($named, ...array_filter((array) ($node->attributes[$attribute] ?? []), is_string(...)));
                }
                $haystack = implode("\n", $named);
                if ($exact || array_all($words, static fn (string $word): bool => mb_stripos($haystack, $word) !== false)) {
                    $results[] = [
                        'service' => $flow->name,
                        'nodeId' => $node->id,
                        'kind' => $node->kind->value,
                        'kindLabel' => DiagramNode::kindLabelOf($node->kind->value),
                        'family' => DiagramNode::familyOf($node->kind->value),
                        'label' => $label,
                        'fullLabel' => $node->label,
                        'exact' => $exact,
                        'named' => array_all($words, static fn (string $word): bool => mb_stripos($label, $word) !== false),
                    ];
                }
            }
        }
        usort($results, static fn (array $one, array $other): int => [$other['exact'], $other['named'], $one['label'], $one['service']] <=> [$one['exact'], $one['named'], $other['label'], $other['service']]);

        return new self($results);
    }

    /**
     * @return array{service: string, nodeId: string}|null
     */
    public function onlyMatch(): ?array
    {
        $exact = array_values(array_filter($this->results, static fn (array $result): bool => $result['exact']));

        return count($this->results) === 1 || count($exact) === 1 ? $this->results[0] : null;
    }
}
