<?php

declare(strict_types=1);

namespace App\Controller;

use App\DeadLetter\DeadLetterFilter;
use App\DeadLetter\DeadLetterNotice;
use App\DeadLetter\DeadLetterOverview;
use App\Snapshot\Refusals;
use App\Snapshot\ServiceScope;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeadLetterController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots)
    {
    }

    #[Route('/dead-letters', name: 'dead_letters', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filter = DeadLetterFilter::fromInput($request->query);
        $snapshots = $this->snapshots->viewScopesAndRequestStale();

        $notice = ($invalidDate = $filter->invalidDateField()) !== null ? DeadLetterNotice::invalidDate($invalidDate) : DeadLetterNotice::fromInput($request->query, array_map(static fn ($snapshot) => $snapshot->scope(), $snapshots));

        return $this->render('dead_letters/list.html.twig', [
            'filter' => $filter,
            'refusals' => $notice?->code === DeadLetterNotice::ACTION_SENT ? Refusals::of($snapshots, 'ecotone.pulse.dead_letter.' . $notice->parameters['action'], $notice->services()) : [],
            'services' => DeadLetterOverview::of($snapshots, $filter),
            'serviceNames' => array_map(static fn ($snapshot) => $snapshot->scope(), $snapshots),
            'notice' => $notice,
            'channels' => DeadLetterOverview::channelsByService($snapshots),
            'sharedStores' => DeadLetterOverview::servicesSharingAStore($snapshots),
            'waiting' => Snapshots::needsAutoRefresh($snapshots),
        ]);
    }

    #[Route('/dead-letters/{service}/{messageId}', name: 'dead_letter', methods: ['GET'], requirements: ['service' => '[^/]+', 'messageId' => '[^/]+'])]
    public function detail(string $service, string $messageId): Response
    {
        $entry = DeadLetterOverview::entry($this->snapshots->scopes(), $service, $messageId);
        if ($entry === null) {
            throw $this->createNotFoundException(sprintf('Dead letter "%s" of service "%s" is not in the latest 100 of its snapshot. It may have been replayed or deleted, or it is older than the snapshot lists; go back to the dead letters list.', $messageId, $service));
        }

        return $this->render('dead_letters/detail.html.twig', [
            'service' => $service,
            'scope' => ServiceScope::fromKey($service),
            'entry' => $entry,
            'similar' => DeadLetterOverview::similar($this->snapshots->scopes(), $service, $entry),
            'failure' => DeadLetterOverview::failure($this->snapshots->scopes(), $service, (string) ($entry['failureId'] ?? '')),
            'payloadJson' => self::prettyJson($entry['payload'] ?? null),
        ]);
    }

    private static function prettyJson(mixed $payload): ?string
    {
        if (! is_string($payload) || ! str_starts_with(ltrim($payload), '{') && ! str_starts_with(ltrim($payload), '[')) {
            return null;
        }

        $decoded = json_decode($payload);

        return $decoded === null ? null : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
