<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\ComparedFlowDiagram;
use App\Graph\ServiceFlow;
use App\Graph\ServiceFlows;
use App\Snapshot\ServiceScope;
use App\Snapshot\Snapshots;
use App\Trace\TraceAnswer;
use App\Trace\TraceAnswerState;
use App\Trace\TraceQueries;
use App\Trace\TraceQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FlowCompareController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly TraceQueries $queries)
    {
    }

    #[Route('/flow/compare', name: 'flow_compare', methods: ['GET'])]
    public function compare(Request $request): Response
    {
        $flows = ServiceFlows::of($this->snapshots->viewAndRequestStale())->flows;
        $services = array_map(fn (ServiceFlow $flow): string => $flow->name, $flows);
        $service = trim((string) $request->query->get('service', ''));
        if ($service === '' && count($services) === 1) {
            $service = $services[0];
        }
        if ($service === '') {
            return $this->render('graph/compare.html.twig', ['services' => $services, 'service' => null, 'waiting' => false]);
        }
        $live = array_values(array_filter($flows, fn (ServiceFlow $flow): bool => $flow->name === $service))[0] ?? null;
        if ($live === null) {
            throw $this->createNotFoundException(sprintf('Service "%s" has not answered Pulse with its flow graph, so its versions cannot be compared. Services with a flow graph: %s. Open /flow/compare to choose one.', $service, $services === [] ? 'none' : implode(', ', $services)));
        }

        $scope = ServiceScope::fromKey($service);
        $list = $this->queries->askAll([$scope], TraceQuery::flowVersions())[0];
        $versions = array_values(array_filter($list->result['versions'] ?? [], 'is_array'));
        $view = [
            'services' => $services,
            'service' => $service,
            'list' => $list,
            'configured' => (bool) ($list->result['configured'] ?? true),
            'versions' => $versions,
            'from' => null,
            'to' => null,
            'refusal' => null,
            'compared' => null,
            'waiting' => $list->state === TraceAnswerState::Waiting,
        ];
        if (! $list->hasResult() || $versions === []) {
            return $this->render('graph/compare.html.twig', $view);
        }

        $from = trim((string) $request->query->get('from', '')) ?: (string) $versions[count($versions) - 1]['label'];
        $to = trim((string) $request->query->get('to', ''));
        $earlier = $this->queries->askAll([$scope], TraceQuery::flowVersion($from))[0];
        $later = $to === '' ? null : $this->queries->askAll([$scope], TraceQuery::flowVersion($to))[0];
        $view = [...$view, 'from' => $from, 'to' => $to, 'waiting' => $view['waiting'] || self::isWaiting($earlier) || ($later !== null && self::isWaiting($later))];
        foreach (array_filter([$earlier, $later]) as $answer) {
            if (in_array($answer->state, [TraceAnswerState::Refused, TraceAnswerState::NotAnswering], true)) {
                return $this->render('graph/compare.html.twig', [...$view, 'refusal' => $answer->failure ?? sprintf('%s is not answering for this flow version.', $service), 'waiting' => false]);
            }
        }
        $earlierGraph = is_array($earlier->result['graph'] ?? null) ? ServiceFlow::graphOf($earlier->result['graph']) : null;
        $laterGraph = $later === null ? $live->graph : (is_array($later->result['graph'] ?? null) ? ServiceFlow::graphOf($later->result['graph']) : null);
        if ($earlierGraph === null || $laterGraph === null) {
            return $this->render('graph/compare.html.twig', $view);
        }

        return $this->render('graph/compare.html.twig', [...$view, 'compared' => ComparedFlowDiagram::of($service, $earlierGraph->compareTo($laterGraph)), 'waiting' => false]);
    }

    private static function isWaiting(TraceAnswer $answer): bool
    {
        return $answer->state === TraceAnswerState::Waiting && ! $answer->hasResult();
    }
}
