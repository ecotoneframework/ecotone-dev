<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use function json_encode;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

abstract class TracingTest extends TestCase
{
    protected function prepareTracer(SpanExporterInterface $exporter): TracerInterface
    {
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                $exporter
            )
        );

        return $tracerProvider->getTracer('io.opentelemetry.contrib.php');
    }

    public function buildTree(InMemoryExporter $exporter): array
    {
        $tree = [];
        foreach ($exporter->getSpans() as $span) {
            $preparedSpan = [
                'details' => [
                    'name' => $span->getName(),
                    'span_id' => $span->getSpanId(),
                    'parent_id' => $span->getParentContext()->isValid() ? $span->getParentSpanId() : null,
                    'attributes' => $span->getAttributes()->toArray(),
                    'kind' => $span->getKind(),
                    'links' => $span->getLinks(),
                ],
                'children' => [],
            ];

            $tree = $this->putInTree($preparedSpan, $tree, true);
        }

        return $tree;
    }

    public function compareTreesByDetails(
        array $expectedTree,
        array $collectedTree
    ) {
        foreach ($expectedTree as $expectedNode) {
            $this->findAndCompareNode($expectedNode, $collectedTree);
        }
    }

    private function putInTree(array $searchedSpan, array $tree, bool $isRoot): array
    {
        $searchedSpanId = $searchedSpan['details']['span_id'];
        $searchedParentId = $searchedSpan['details']['parent_id'];

        foreach ($tree as $nodeId => $node) {
            /** Connect all non parent node */
            if ($node['details']['parent_id'] === $searchedSpanId) {
                unset($tree[$nodeId]);
                $searchedSpan['children'][$nodeId] = $node;
            }
        }
        $changedStructure = $tree;

        foreach ($tree as $nodeId => $node) {
            if ($nodeId === $searchedParentId) {
                if (in_array($searchedSpanId, array_keys($node['children']))) {
                    continue;
                }

                $changedStructure[$nodeId]['children'][$searchedSpanId] = $searchedSpan;
            } elseif ($node['details']['parent_id'] === $searchedSpanId) {
                unset($changedStructure[$nodeId]);
                $searchedSpan['children'][$nodeId] = $node;

                $changedStructure = $this->putInTree(
                    $searchedSpan,
                    $changedStructure,
                    true
                );
            } else {
                // go to children
                $changedStructure[$nodeId]['children'] = $this->putInTree(
                    $searchedSpan,
                    $node['children'],
                    false
                );
            }
        }

        if ($isRoot && $changedStructure == $tree) {
            $changedStructure[$searchedSpanId] = $searchedSpan;
        }

        return $changedStructure;
    }

    private function findAndCompareNode(array $expectedTreeNode, array $collectedTree): void
    {
        foreach ($collectedTree as $collectedNode) {
            if ($expectedTreeNode['details']['name'] === $collectedNode['details']['name']) {
                foreach ($expectedTreeNode['details'] as $expectedKey => $expectedValue) {
                    $this->assertArrayHasKey($expectedKey, $collectedNode['details'], "Expected key {$expectedKey} is not present in node {$collectedNode['details']['name']}");
                    if ($expectedKey === 'attributes') {
                        foreach ($expectedValue as $expectedAttributeKey => $expectedAttributeValue) {
                            $this->assertArrayHasKey($expectedAttributeKey, $collectedNode['details'][$expectedKey], "Expected key {$expectedAttributeKey} is not present in node {$collectedNode['details']['name']}");
                            $this->assertSame($expectedAttributeValue, $collectedNode['details'][$expectedKey][$expectedAttributeKey], "Expected value for key {$expectedAttributeKey} is {$expectedAttributeValue}, but got {$collectedNode['details'][$expectedKey][$expectedAttributeKey]} in node {$collectedNode['details']['name']}");
                        }
                    } else {
                        $this->assertSame(
                            $expectedValue,
                            $collectedNode['details'][$expectedKey],
                            "Expected value for key {$expectedKey} in node {$collectedNode['details']['name']}"
                        );
                    }
                }
                $this->compareTreesByDetails($expectedTreeNode['children'], $collectedNode['children']);

                return;
            }
        }

        $this->assertTrue(false, "Could not find node with name {$expectedTreeNode['details']['name']}. Nodes at this level: " . json_encode($collectedTree));
    }

    public function getNodeAtTargetedSpan(array $expectedTreeNode, array $collectedTree): array
    {
        foreach ($collectedTree as $collectedNode) {
            if ($expectedTreeNode['details']['name'] === $collectedNode['details']['name']) {
                foreach ($expectedTreeNode['details'] as $expectedKey => $expectedValue) {
                    $this->assertArrayHasKey($expectedKey, $collectedNode['details'], "Expected key {$expectedKey} is not present in node {$collectedNode['details']['name']}");
                    $this->assertSame($expectedValue, $collectedNode['details'][$expectedKey], "Expected value for key {$expectedKey} is {$expectedValue}, but got {$collectedNode['details'][$expectedKey]} in node {$collectedNode['details']['name']}");
                }

                if (! array_key_exists('child', $expectedTreeNode)) {
                    return $collectedNode;
                }

                return $this->getNodeAtTargetedSpan($expectedTreeNode['child'], $collectedNode['children']);
            }
        }

        $this->assertTrue(false, "Could not find node with name {$expectedTreeNode['details']['name']}. Nodes at this level: " . json_encode($collectedTree));
    }
}
