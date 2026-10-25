<?php

declare(strict_types=1);

namespace App\Controller;

use App\DeadLetter\DeadLetterAction;
use App\DeadLetter\DeadLetterActionRequester;
use App\DeadLetter\DeadLetterFilter;
use App\DeadLetter\DeadLetterNotice;
use App\DeadLetter\DeadLetterOverview;
use App\Snapshot\ReplyAwaiter;
use App\Snapshot\ServiceScope;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
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
        private readonly ReplyAwaiter $awaiter,
    ) {
    }

    #[Route('/dead-letters/actions', name: 'dead_letter_actions', methods: ['POST'])]
    public function act(Request $request): Response
    {
        $failure = (string) $request->request->get('failure', '');
        $deleteFailure = (string) $request->request->get('deleteFailure', '');
        $byFailure = $failure !== '' || $deleteFailure !== '';
        $replay = $byFailure ? $failure !== '' : $request->request->get('action') === 'replay';
        $filter = DeadLetterFilter::fromInput($request->request);
        $byFilter = $request->request->get('scope') === 'filter';
        if (($invalidDate = $filter->invalidDateField()) !== null) {
            return $this->back($filter, DeadLetterNotice::invalidDate($invalidDate));
        }

        $actions = $byFailure
            ? $this->actionsForFailure($replay ? $failure : $deleteFailure, $replay, $request->request->all()['targetChannel'] ?? [])
            : ($byFilter
            ? $this->actionsForFilter($filter, $replay, $replay ? trim((string) $request->request->get('targetChannel', '')) : '')
            : $this->actionsForSelection($request->request->all('selected'), $replay, $replay ? $request->request->all()['targetChannel'] ?? [] : []));
        if ($actions instanceof DeadLetterNotice) {
            return $this->back($filter, $actions);
        }
        if (! $replay && $request->request->get('confirm') !== '1') {
            return $this->render('dead_letters/confirm_delete.html.twig', [
                'filter' => $filter,
                'services' => array_keys($actions),
                'selection' => $byFilter || $byFailure ? [] : $this->selectedIds($request->request->all('selected')),
                'deleteFailure' => $byFailure ? $deleteFailure : '',
                'failure' => $byFailure ? $this->failureOf($deleteFailure) : null,
            ]);
        }

        $failureLabel = $byFailure ? DeadLetterOverview::failureLabel($this->failureOf($failure !== '' ? $failure : $deleteFailure)) : '';
        $failed = [];
        $results = [];
        foreach ($actions as $service => $action) {
            $result = $this->requester->send(ServiceScope::fromKey($service), $action);
            $results[] = $result;
            if ($result->failed) {
                $failed[] = $service;
            }
        }
        $this->awaiter->await($results);

        return $this->back($filter, $failed === []
            ? DeadLetterNotice::actionSent($replay ? 'replay' : 'delete', array_keys($actions), $this->affectedBy(array_keys($actions)), $failureLabel)
            : DeadLetterNotice::sendFailed($failed));
    }

    /**
     * @return array<string, DeadLetterAction>|DeadLetterNotice
     */
    private function actionsForFailure(string $failure, bool $replay, mixed $targetChannels): array|DeadLetterNotice
    {
        $decoded = json_decode($failure, true);
        if (! is_array($decoded) || count($decoded) !== 2 || ! array_is_list($decoded) || array_filter($decoded, is_string(...)) !== $decoded || preg_match('/^[0-9a-f]{12}$/', $decoded[1]) !== 1) {
            return DeadLetterNotice::nothingSelected();
        }
        [$service, $failureId] = $decoded;
        $configured = $this->servicesWithDeadLetters($service);
        if ($configured instanceof DeadLetterNotice) {
            return $configured;
        }
        $targetChannel = $replay && is_array($targetChannels) && is_string($targetChannels[$service] ?? null) ? trim($targetChannels[$service]) : '';
        if ($targetChannel !== '' && ! $this->registersChannel($service, $targetChannel)) {
            return DeadLetterNotice::unknownChannel($service, $targetChannel);
        }

        return [$service => $replay ? DeadLetterAction::replayMatching(['failureId' => $failureId], $targetChannel) : DeadLetterAction::deleteMatching(['failureId' => $failureId])];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function failureOf(string $failure): ?array
    {
        $decoded = json_decode($failure, true);

        return is_array($decoded) && count($decoded) === 2 && is_string($decoded[0] ?? null) && is_string($decoded[1] ?? null) ? DeadLetterOverview::failure($this->snapshots->scopes(), $decoded[0], $decoded[1]) : null;
    }

    /**
     * @param list<string> $services
     */
    private function affectedBy(array $services): ?int
    {
        $affected = 0;
        foreach ($this->snapshots->scopes() as $snapshot) {
            if (! in_array($snapshot->scope(), $services, true)) {
                continue;
            }
            if ($snapshot->status !== ServiceStatus::Connected || ! is_int($snapshot->snapshot['deadLetterAction']['affected'] ?? null)) {
                return null;
            }
            $affected += $snapshot->snapshot['deadLetterAction']['affected'];
        }

        return $affected;
    }

    /**
     * @return array<string, DeadLetterAction>|DeadLetterNotice
     */
    private function actionsForFilter(DeadLetterFilter $filter, bool $replay, string $targetChannel): array|DeadLetterNotice
    {
        if ($filter->hasSearch()) {
            return DeadLetterNotice::searchNotStored();
        }
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
        $services = DeadLetterOverview::withoutServicesSharingAnEarlierStore($this->snapshots->scopes(), $services);
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

        $idsByService = $this->selectedIds($selected);
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
     * @param array<mixed> $selected
     * @return array<string, list<string>>
     */
    private function selectedIds(array $selected): array
    {
        $idsByService = [];
        foreach ($selected as $service => $messageIds) {
            $messageIds = array_values(array_filter(is_array($messageIds) ? $messageIds : [], static fn (mixed $messageId) => is_string($messageId) && $messageId !== ''));
            if ($messageIds !== []) {
                $idsByService[(string) $service] = $messageIds;
            }
        }

        return $idsByService;
    }

    /**
     * @return list<string>|DeadLetterNotice
     */
    private function servicesWithDeadLetters(?string $onlyService): array|DeadLetterNotice
    {
        $snapshots = $this->snapshots->scopes();
        if ($onlyService !== null && $onlyService !== '' && ! in_array($onlyService, array_map(static fn (ServiceSnapshot $snapshot) => $snapshot->scope(), $snapshots), true)) {
            return DeadLetterNotice::unknownService();
        }

        $services = [];
        foreach ($snapshots as $snapshot) {
            if (($onlyService === null || $onlyService === '' || $onlyService === $snapshot->scope() || $onlyService === $snapshot->service) && is_array($snapshot->snapshot['deadLetters'] ?? null)) {
                $services[] = $snapshot->scope();
            }
        }
        if ($services === []) {
            return $onlyService !== null && $onlyService !== '' ? DeadLetterNotice::notConfigured($onlyService) : DeadLetterNotice::noDeadLettersAnywhere();
        }

        return $services;
    }

    private function registersChannel(string $service, string $channel): bool
    {
        return in_array($channel, DeadLetterOverview::channelsByService($this->snapshots->scopes())[$service] ?? [], true);
    }

    private function back(DeadLetterFilter $filter, DeadLetterNotice $notice): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('dead_letters', $notice->toQuery() + $filter->toQuery()));
    }
}
