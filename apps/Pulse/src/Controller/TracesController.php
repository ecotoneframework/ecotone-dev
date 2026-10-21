<?php

declare(strict_types=1);

namespace App\Controller;

use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
use App\Snapshot\Snapshots;
use App\Trace\CrossServiceTrace;
use App\Trace\FlowTypeOverview;
use App\Trace\TraceAnswerState;
use App\Trace\TraceQueries;
use App\Trace\TraceQuery;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TracesController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly TraceQueries $queries)
    {
    }

    #[Route('/traces', name: 'traces', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $traceId = trim((string) $request->query->get('traceId', ''));
        if ($traceId !== '' && ! str_contains($traceId, '/')) {
            return new RedirectResponse($this->generateUrl('trace_detail', ['traceId' => $traceId]));
        }

        $snapshots = $this->snapshots->waitForReplies($this->snapshots->viewAndRequestStale());

        return $this->render('traces/index.html.twig', [
            'services' => FlowTypeOverview::of($snapshots),
            'waiting' => Snapshots::needsAutoRefresh($snapshots),
        ]);
    }

    #[Route('/traces/trace/{traceId}', name: 'trace_detail', methods: ['GET'], requirements: ['traceId' => '[^/]+'])]
    public function detail(string $traceId): Response
    {
        $snapshots = $this->snapshots->waitForReplies($this->snapshots->viewAndRequestStale());
        $query = TraceQuery::trace($traceId);
        $asked = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->status === ServiceStatus::Connected && is_array($snapshot->snapshot['tracing'] ?? null)) {
                $asked[] = $snapshot->service;
            }
        }
        $answers = [];
        foreach ($this->queries->askAll($asked, $query) as $answer) {
            $answers[$answer->service] = $answer;
        }

        $states = [];
        $spans = [];
        $deadLetters = [];
        foreach ($snapshots as $snapshot) {
            $answer = $answers[$snapshot->service] ?? null;
            $states[] = [
                'service' => $snapshot->service,
                'state' => match (true) {
                    $answer !== null => $answer->state->value,
                    $snapshot->status !== ServiceStatus::Connected => 'not_connected',
                    default => 'tracing_disabled',
                },
                'status' => $snapshot->status->name,
                'failure' => $answer?->failure,
                'truncated' => (bool) ($answer?->result['truncated'] ?? false),
                'totalSpans' => (int) ($answer?->result['totalSpans'] ?? 0),
            ];
            $spans[$snapshot->service] = $answer?->result['spans'] ?? [];
            foreach ($snapshot->snapshot['deadLetters']['recent'] ?? [] as $entry) {
                if (is_string($entry['messageId'] ?? null)) {
                    $deadLetters[$snapshot->service][$entry['messageId']] = $entry;
                }
            }
        }

        return $this->render('traces/detail.html.twig', [
            'traceId' => $traceId,
            'trace' => CrossServiceTrace::merge($spans, $deadLetters),
            'states' => $states,
            'waiting' => TraceQueries::anyWaiting(array_values($answers)),
        ]);
    }

    #[Route('/traces/{service}/{flowType}', name: 'trace_list', methods: ['GET'], requirements: ['service' => '[^/]+', 'flowType' => '.+'])]
    public function list(Request $request, string $service, string $flowType): Response
    {
        $snapshots = $this->snapshots->waitForReplies($this->snapshots->viewAndRequestStale());
        $snapshot = $this->snapshotOf($snapshots, $service);
        if ($snapshot === null) {
            throw $this->createNotFoundException(sprintf('Service "%s" is not configured in Pulse. Add it to PULSE_SERVICES.', $service));
        }

        $page = max(1, $request->query->getInt('page', 1));
        $tracing = is_array($snapshot->snapshot['tracing'] ?? null);
        $answer = $tracing ? $this->queries->askAll([$service], TraceQuery::list($flowType, $page))[0] : null;
        $total = (int) ($answer?->result['total'] ?? 0);

        return $this->render('traces/list.html.twig', [
            'service' => $service,
            'flowType' => $flowType,
            'tracing' => $tracing,
            'answer' => $answer,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / TraceQuery::PER_PAGE)),
            'traces' => $answer?->result['traces'] ?? [],
            'statistics' => self::statisticsOf($snapshot, $flowType),
            'waiting' => $answer !== null && $answer->state === TraceAnswerState::Waiting,
        ]);
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     */
    private function snapshotOf(array $snapshots, string $service): ?ServiceSnapshot
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->service === $service) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function statisticsOf(ServiceSnapshot $snapshot, string $flowType): ?array
    {
        foreach (FlowTypeOverview::of([$snapshot])[0]['flowTypes'] as $statistics) {
            if (($statistics['flowType'] ?? null) === $flowType) {
                return $statistics;
            }
        }

        return null;
    }
}
