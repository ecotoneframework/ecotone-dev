<?php

declare(strict_types=1);

namespace App\Controller;

use App\Projection\RebuildNotice;
use App\Projection\RebuildRequester;
use App\Snapshot\ReplyAwaiter;
use App\Snapshot\ServiceScope;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectionRebuildController extends AbstractController
{
    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly RebuildRequester $requester,
        private readonly ReplyAwaiter $awaiter,
    ) {
    }

    #[Route('/services/{service}/projections/{projection}/rebuild', name: 'service_projection_rebuild', methods: ['POST'], requirements: ['service' => '[^/]+', 'projection' => '[^/]+'])]
    public function rebuild(string $service, string $projection, Request $request): RedirectResponse
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
            return $this->back($service, RebuildNotice::of(RebuildNotice::UNKNOWN_PROJECTION, $projection));
        }
        if ($request->request->get('confirm') !== '1') {
            return $this->back($service, RebuildNotice::of(RebuildNotice::NOT_CONFIRMED, $projection));
        }

        $result = $this->requester->send($scope, $projection);
        $this->awaiter->await([$result]);

        return $this->back($service, RebuildNotice::of($result->failed ? RebuildNotice::SEND_FAILED : RebuildNotice::REQUESTED, $projection));
    }

    private function back(string $service, RebuildNotice $notice): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('service_projections', ['service' => $service] + $notice->toQuery()));
    }
}
