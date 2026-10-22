<?php

declare(strict_types=1);

namespace App\Controller;

use App\Projection\ProjectionActionNotice;
use App\Projection\ProjectionActionRequester;
use App\Projection\Rebuild;
use App\Snapshot\ReplyAwaiter;
use App\Snapshot\ServiceScope;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectionActionController extends AbstractController
{
    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly ProjectionActionRequester $requester,
        private readonly ReplyAwaiter $awaiter,
    ) {
    }

    #[Route('/services/{service}/projections/{projection}/rebuild', name: 'service_projection_rebuild', methods: ['POST'], requirements: ['service' => '[^/]+', 'projection' => '[^/]+'])]
    public function rebuild(string $service, string $projection, Request $request): RedirectResponse
    {
        return $this->act(Rebuild::REBUILD, $service, $projection, $request);
    }

    #[Route('/services/{service}/projections/{projection}/backfill', name: 'service_projection_backfill', methods: ['POST'], requirements: ['service' => '[^/]+', 'projection' => '[^/]+'])]
    public function backfill(string $service, string $projection, Request $request): RedirectResponse
    {
        return $this->act(Rebuild::BACKFILL, $service, $projection, $request);
    }

    private function act(string $action, string $service, string $projection, Request $request): RedirectResponse
    {
        $tenant = $request->request->get('tenant');
        $scope = is_string($tenant) && $tenant !== '' ? ServiceScope::ofTenant($service, $tenant) : ServiceScope::ofService($service);
        $known = false;
        foreach ($this->snapshots->scopes() as $snapshot) {
            if ($snapshot->scope() !== $scope->key()) {
                continue;
            }
            foreach ($snapshot->snapshot['projections'] ?? [] as $reported) {
                $known = $known || (is_array($reported) && ($reported['name'] ?? null) === $projection);
            }
        }
        if (! $known) {
            return $this->back(ServiceScope::ofService($service), ProjectionActionNotice::of($action, ProjectionActionNotice::UNKNOWN_PROJECTION, $projection));
        }
        if ($request->request->get('confirm') !== '1') {
            return $this->back($scope, ProjectionActionNotice::of($action, ProjectionActionNotice::NOT_CONFIRMED, $projection));
        }

        $result = $this->requester->send($scope, $action, $projection);
        $this->awaiter->await([$result]);

        return $this->back($scope, ProjectionActionNotice::of($action, $result->failed ? ProjectionActionNotice::SEND_FAILED : ProjectionActionNotice::REQUESTED, $projection));
    }

    private function back(ServiceScope $scope, ProjectionActionNotice $notice): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('service_projections', ['service' => $scope->key()] + $notice->toQuery()));
    }
}
