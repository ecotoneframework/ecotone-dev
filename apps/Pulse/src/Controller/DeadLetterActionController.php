<?php

declare(strict_types=1);

namespace App\Controller;

use App\DeadLetter\DeadLetterAction;
use App\DeadLetter\DeadLetterActionRequester;
use App\DeadLetter\DeadLetterFilter;
use App\DeadLetter\DeadLetterNotice;
use App\DeadLetter\DeadLetterOverview;
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
        $byFilter = $request->request->get('scope') === 'filter';
        if (($invalidDate = $filter->invalidDateField()) !== null) {
            return $this->back($filter, DeadLetterNotice::invalidDate($invalidDate));
        }

        $actions = $byFilter
            ? $this->actionsForFilter($filter, $replay, $replay ? trim((string) $request->request->get('targetChannel', '')) : '')
            : $this->actionsForSelection($request->request->all('selected'), $replay, $replay ? $request->request->all()['targetChannel'] ?? [] : []);
        if ($actions instanceof DeadLetterNotice) {
            return $this->back($filter, $actions);
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
            ? DeadLetterNotice::actionSent($replay ? 'replay' : 'delete', array_keys($actions))
            : DeadLetterNotice::sendFailed($failed));
    }

    /**
     * @return array<string, DeadLetterAction>|DeadLetterNotice
     */
    private function actionsForFilter(DeadLetterFilter $filter, bool $replay, string $targetChannel): array|DeadLetterNotice
    {
        if (! $filter->hasStoredConditions()) {
            return DeadLetterNotice::noFilterCondition();
        }

        $services = $this->servicesWithDeadLetters($filter->service);
        if ($services instanceof DeadLetterNotice) {
            return $services;
        }
        if ($targetChannel !== '') {
            if ($filter->service === '') {
                return DeadLetterNotice::targetNeedsService();
            }
            if (! $this->registersChannel($filter->service, $targetChannel)) {
                return DeadLetterNotice::unknownChannel($filter->service, $targetChannel);
            }
        }
        $services = DeadLetterOverview::withoutServicesSharingAnEarlierStore($this->snapshots->all(), $services);
        $conditions = $filter->storedConditions();
        $action = $replay ? DeadLetterAction::replayMatching($conditions, $targetChannel) : DeadLetterAction::deleteMatching($conditions);

        return array_fill_keys($services, $action);
    }

    /**
     * @param array<mixed> $selected
     * @return array<string, DeadLetterAction>|DeadLetterNotice
     */
    private function actionsForSelection(array $selected, bool $replay, mixed $targetChannels): array|DeadLetterNotice
    {
        if (! is_array($targetChannels)) {
            return DeadLetterNotice::targetPerService();
        }

        $idsByService = [];
        foreach ($selected as $service => $messageIds) {
            $messageIds = array_values(array_filter(is_array($messageIds) ? $messageIds : [], static fn (mixed $messageId) => is_string($messageId) && $messageId !== ''));
            if ($messageIds !== []) {
                $idsByService[(string) $service] = $messageIds;
            }
        }
        if ($idsByService === []) {
            return DeadLetterNotice::nothingSelected();
        }

        $configured = $this->servicesWithDeadLetters(null);
        $actions = [];
        foreach ($idsByService as $service => $messageIds) {
            if (! is_array($configured) || ! in_array($service, $configured, true)) {
                return DeadLetterNotice::notConfigured($service);
            }
            $targetChannel = is_string($targetChannels[$service] ?? null) ? trim($targetChannels[$service]) : '';
            if ($targetChannel !== '' && ! $this->registersChannel($service, $targetChannel)) {
                return DeadLetterNotice::unknownChannel($service, $targetChannel);
            }
            $actions[$service] = $replay ? DeadLetterAction::replayIds($messageIds, $targetChannel) : DeadLetterAction::deleteIds($messageIds);
        }

        return $actions;
    }

    /**
     * @return list<string>|DeadLetterNotice
     */
    private function servicesWithDeadLetters(?string $onlyService): array|DeadLetterNotice
    {
        $snapshots = $this->snapshots->all();
        if ($onlyService !== null && $onlyService !== '' && ! in_array($onlyService, array_map(static fn (ServiceSnapshot $snapshot) => $snapshot->service, $snapshots), true)) {
            return DeadLetterNotice::unknownService();
        }

        $services = [];
        foreach ($snapshots as $snapshot) {
            if (($onlyService === null || $onlyService === '' || $onlyService === $snapshot->service) && is_array($snapshot->snapshot['deadLetters'] ?? null)) {
                $services[] = $snapshot->service;
            }
        }
        if ($services === []) {
            return $onlyService !== null && $onlyService !== '' ? DeadLetterNotice::notConfigured($onlyService) : DeadLetterNotice::noDeadLettersAnywhere();
        }

        return $services;
    }

    private function registersChannel(string $service, string $channel): bool
    {
        return in_array($channel, DeadLetterOverview::channelsByService($this->snapshots->all())[$service] ?? [], true);
    }

    private function back(DeadLetterFilter $filter, DeadLetterNotice $notice): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('dead_letters', $notice->toQuery() + $filter->toQuery()));
    }
}
