<?php

declare(strict_types=1);

namespace App\Controller;

use App\Gathering\GatheringActionRequester;
use App\Snapshot\ReplyAwaiter;
use App\Snapshot\ServiceScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
final class GatheringActionController extends AbstractController
{
    public function __construct(private readonly GatheringActionRequester $requester, private readonly ReplyAwaiter $awaiter)
    {
    }

    #[Route('/services/{service}/gathering/{groupId}/release', name: 'service_gathering_release', methods: ['POST'], requirements: ['service' => '[^/]+', 'groupId' => '[^/]+'])]
    public function release(string $service, string $groupId): RedirectResponse
    {
        $scope = ServiceScope::fromKey($service);
        $result = $this->requester->send($scope, 'release', $groupId);
        $this->awaiter->await([$result]);

        return $this->redirectToRoute('service_gathering', ['service' => $scope->key(), 'action' => 'release', 'status' => $result->failed ? 'send_failed' : 'requested', 'groupId' => $groupId]);
    }
}
