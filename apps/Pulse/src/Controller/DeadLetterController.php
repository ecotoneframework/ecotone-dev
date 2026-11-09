<?php

declare(strict_types=1);

namespace App\Controller;

use App\DeadLetter\DeadLetterFilter;
use App\DeadLetter\DeadLetterNotice;
use App\DeadLetter\DeadLetterOverview;
use App\Snapshot\Refusals;
use App\Snapshot\ServiceScope;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;
use App\Ui\DeadLetterDetail;
use App\Ui\DeadLetterList;
use App\Ui\ServiceOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeadLetterController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly ServiceOverview $overview)
    {
    }

    #[Route('/dead-letters', name: 'dead_letters', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filter = DeadLetterFilter::fromInput($request->query);
        $snapshots = $this->snapshots->viewScopesAndRequestStale();

        $notice = ($invalidDate = $filter->invalidDateField()) !== null ? DeadLetterNotice::invalidDate($invalidDate) : DeadLetterNotice::fromInput($request->query, array_map(static fn ($snapshot) => $snapshot->scope(), $snapshots));

        $sharedStores = DeadLetterOverview::servicesSharingAStore($snapshots);

        return $this->render('dead_letters/list.html.twig', [
            'filter' => $filter,
            'refusals' => $notice?->code === DeadLetterNotice::ACTION_SENT ? Refusals::of($snapshots, 'ecotone.pulse.dead_letter.' . $notice->parameters['action'], $notice->services()) : [],
            'list' => DeadLetterList::of(DeadLetterOverview::of($snapshots, $filter), $filter, $sharedStores),
            'matchingCount' => DeadLetterOverview::matchingCount($snapshots, $filter),
            'serviceNames' => array_map(static fn ($snapshot) => $snapshot->scope(), $snapshots),
            'notice' => $notice,
            'channels' => DeadLetterOverview::channelsByService($snapshots),
            'sharedStores' => $sharedStores,
            'header' => $filter->service === '' ? null : $this->serviceHeader(ServiceScope::fromKey($filter->service), $snapshots),
            'waiting' => Snapshots::needsAutoRefresh($snapshots),
        ]);
    }

    #[Route('/dead-letters/{service}/{messageId}', name: 'dead_letter', methods: ['GET'], requirements: ['service' => '[^/]+', 'messageId' => '[^/]+'])]
    public function detail(Request $request, string $service, string $messageId): Response
    {
        $snapshots = $this->snapshots->scopes();
        $entry = DeadLetterOverview::entry($snapshots, $service, $messageId);
        if ($entry === null) {
            throw $this->createNotFoundException(sprintf('Dead letter "%s" of service "%s" is not in the latest 100 of its snapshot. It may have been replayed or deleted, or it is older than the snapshot lists; go back to the dead letters list.', $messageId, $service));
        }
        $failureId = (string) ($entry['failureId'] ?? '');
        $failure = DeadLetterOverview::failure($snapshots, $service, $failureId);
        $scope = ServiceScope::fromKey($service);

        return $this->render('dead_letters/detail.html.twig', [
            'service' => $service,
            'scope' => $scope,
            'entry' => $entry,
            'detail' => DeadLetterDetail::of($service, $entry, $failure, DeadLetterOverview::entriesOfFailure($snapshots, $service, $failureId), self::listFilterOf($request)),
            'similar' => DeadLetterOverview::similar($snapshots, $service, $entry),
            'failure' => $failure,
            'header' => $this->serviceHeader($scope, $snapshots),
        ]);
    }

    private static function listFilterOf(Request $request): ?DeadLetterFilter
    {
        if (! $request->query->has('listFilter')) {
            return null;
        }
        parse_str($request->query->getString('listFilter'), $query);

        return DeadLetterFilter::fromInput(new InputBag(array_filter($query, is_string(...))));
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return array{service: ServiceSnapshot, tenant: ?string, tabs: array<string, string>, failureCount: ?int}|null
     */
    private function serviceHeader(ServiceScope $scope, array $snapshots): ?array
    {
        $service = $this->serviceOf($scope->service);
        if ($service === null) {
            return null;
        }
        $focus = null;
        foreach ($snapshots as $snapshot) {
            if ($snapshot->service === $service->service && ($scope->tenant === null ? ! $service->isMultiTenant() : $snapshot->tenant === $scope->tenant)) {
                $focus = $snapshot;
            }
        }

        return [
            'service' => $service,
            'tenant' => $scope->tenant,
            'tabs' => $this->overview->tabRoutes($service, $scope->tenant),
            'failureCount' => $focus?->deadLetterTotal(),
        ];
    }

    private function serviceOf(string $service): ?ServiceSnapshot
    {
        foreach ($this->snapshots->all() as $snapshot) {
            if ($snapshot->service === $service) {
                return $snapshot;
            }
        }

        return null;
    }
}
