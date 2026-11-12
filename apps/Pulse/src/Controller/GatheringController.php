<?php

declare(strict_types=1);

namespace App\Controller;

use App\Snapshot\ServiceScope;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;
use App\Ui\ServiceOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
 */
final class GatheringController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly ServiceOverview $overview)
    {
    }

    #[Route('/services/{service}/gathering', name: 'service_gathering', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function groups(string $service, Request $request): Response
    {
        $scope = ServiceScope::fromKey($service);
        $tenant = $scope->tenant ?? ($request->query->getString('tenant') ?: null);
        $rows = array_values(array_filter($this->snapshots->viewScopesAndRequestStale(), static fn (ServiceSnapshot $row): bool => $row->service === $scope->service && ($tenant === null || $row->tenant === $tenant)));
        $main = array_values(array_filter($this->snapshots->all(), static fn (ServiceSnapshot $row): bool => $row->service === $scope->service));
        if ($main === [] || $rows === []) {
            throw $this->createNotFoundException('No gathering view exists for this service or tenant. Choose a configured service and one of its advertised tenants.');
        }

        return $this->render('services/gathering.html.twig', [
            'service' => $main[0],
            'tenant' => $tenant,
            'scopes' => $rows,
            'tabs' => $this->overview->tabRoutes($main[0], $tenant),
            'failureCount' => null,
            'waiting' => Snapshots::needsAutoRefresh($rows),
        ]);
    }
}
