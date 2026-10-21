<?php

declare(strict_types=1);

namespace App\Controller;

use App\DeadLetter\DeadLetterAction;
use App\DeadLetter\DeadLetterActionRequester;
use App\DeadLetter\DeadLetterFilter;
use App\Snapshot\RequestResult;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeadLetterActionController extends AbstractController
{
    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly DeadLetterActionRequester $requester,
    ) {
    }

    #[Route('/dead-letters/actions', name: 'dead_letter_actions', methods: ['POST'])]
    public function act(Request $request): Response
    {
        $replay = $request->request->get('action') === 'replay';
        $filter = DeadLetterFilter::fromInput($request->request);
        $targetChannel = $replay ? trim((string) $request->request->get('targetChannel', '')) : '';
        $byFilter = $request->request->get('scope') === 'filter';

        $actions = $byFilter
            ? $this->actionsForFilter($filter, $replay, $targetChannel)
            : $this->actionsForSelection($request->request->all('selected'), $replay, $targetChannel);
        if (is_string($actions)) {
            return $this->back($filter, ['actionError' => $actions]);
        }
        if (! $replay && $byFilter && $request->request->get('confirm') !== '1') {
            return $this->render('dead_letters/confirm_delete.html.twig', ['filter' => $filter, 'services' => array_keys($actions)]);
        }

        $failed = [];
        foreach ($actions as $service => $action) {
            if ($this->requester->send($service, $action) === RequestResult::Failed) {
                $failed[] = $service;
            }
        }

        return $this->back($filter, $failed === []
            ? ['actionSent' => ($replay ? 'replay' : 'delete') . ' to ' . implode(', ', array_keys($actions))]
            : ['actionError' => 'The action could not be sent to ' . implode(', ', $failed) . '; the service shows the reason.']);
    }

    /**
     * @return array<string, DeadLetterAction>|string
     */
    private function actionsForFilter(DeadLetterFilter $filter, bool $replay, string $targetChannel): array|string
    {
        if (! $filter->hasStoredConditions()) {
            return 'A filter action needs at least one filter condition (message name, exception class, failed from or failed to), as without one it would apply to every dead letter. Fill one in, or select dead letters by id instead.';
        }

        $services = $this->servicesWithDeadLetters($filter);
        if (is_string($services)) {
            return $services;
        }
        $conditions = $filter->storedConditions();
        $action = $replay ? DeadLetterAction::replayMatching($conditions, $targetChannel) : DeadLetterAction::deleteMatching($conditions);

        return array_fill_keys($services, $action);
    }

    /**
     * @param array<mixed> $selected
     * @return array<string, DeadLetterAction>|string
     */
    private function actionsForSelection(array $selected, bool $replay, string $targetChannel): array|string
    {
        $idsByService = [];
        foreach ($selected as $selection) {
            if (is_string($selection) && str_contains($selection, ':')) {
                [$service, $messageId] = explode(':', $selection, 2);
                $idsByService[$service][] = $messageId;
            }
        }
        if ($idsByService === []) {
            return 'No dead letters were selected. Tick at least one dead letter, or use the filter actions.';
        }

        $configured = $this->servicesWithDeadLetters(new DeadLetterFilter('', '', '', '', ''));
        $actions = [];
        foreach ($idsByService as $service => $messageIds) {
            if (! is_array($configured) || ! in_array($service, $configured, true)) {
                return $this->notConfigured((string) $service);
            }
            $actions[$service] = $replay ? DeadLetterAction::replayIds($messageIds, $targetChannel) : DeadLetterAction::deleteIds($messageIds);
        }

        return $actions;
    }

    /**
     * @return list<string>|string
     */
    private function servicesWithDeadLetters(DeadLetterFilter $filter): array|string
    {
        $snapshots = $this->snapshots->all();
        if ($filter->service !== '' && ! in_array($filter->service, array_map(static fn (ServiceSnapshot $snapshot) => $snapshot->service, $snapshots), true)) {
            return sprintf('Service "%s" is not configured in PULSE_SERVICES, so Pulse cannot send it dead letter actions. Pick one of the listed services.', $filter->service);
        }

        $services = [];
        foreach ($snapshots as $snapshot) {
            if ($filter->selectsService($snapshot->service) && is_array($snapshot->snapshot['deadLetters'] ?? null)) {
                $services[] = $snapshot->service;
            }
        }
        if ($services === []) {
            return $filter->service !== '' ? $this->notConfigured($filter->service) : 'No service has dead letter configured, so there is nothing to act on.';
        }

        return $services;
    }

    private function notConfigured(string $service): string
    {
        return sprintf('Service "%s" has dead letter not configured in its latest snapshot, so Pulse sends it no dead letter actions. Enable it with DbalConfiguration::createWithDefaults()->withDeadLetter(true) in that service, then refresh.', $service);
    }

    /**
     * @param array<string, string> $notice
     */
    private function back(DeadLetterFilter $filter, array $notice): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('dead_letters', $notice + $filter->toQuery()));
    }
}
