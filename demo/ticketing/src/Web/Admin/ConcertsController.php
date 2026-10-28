<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Catalogue\CancelConcert;
use App\Catalogue\CatalogueRefusal;
use App\Catalogue\ChangeTierPrice;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Tier;
use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Modelling\AggregateNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ConcertsController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {
    }

    #[Route('/admin', name: 'admin', methods: ['GET'])]
    public function admin(): Response
    {
        return $this->redirectToRoute('admin_concerts');
    }

    #[Route('/admin/concerts', name: 'admin_concerts', methods: ['GET'])]
    public function list(): Response
    {
        return $this->concertsPage();
    }

    #[Route('/admin/concerts', name: 'admin_concerts_schedule', methods: ['POST'])]
    public function schedule(Request $request): Response
    {
        $artist = trim($request->request->getString('artist'));
        $startsAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $request->request->getString('startsAt'), new DateTimeZone('UTC'));
        if ($startsAt === false) {
            return $this->concertsPage(sprintf('The concert of %s needs a start time, as a date and a time. Pick both in the "Starts at" field and schedule it again.', $artist));
        }

        try {
            $concertId = $this->commandBus->send(new ScheduleConcert(
                venueId: $request->request->getString('venueId'),
                artist: $artist,
                startsAt: $startsAt,
                standardPriceCents: $request->request->getInt('standardPriceCents'),
                vipPriceCents: $request->request->getInt('vipPriceCents'),
                currency: $request->request->getString('currency'),
            ));
        } catch (CatalogueRefusal $refusal) {
            return $this->concertsPage($refusal->getMessage());
        }

        $this->addFlash('notice', sprintf('%s is scheduled.', $artist));

        return $this->redirectToRoute('admin_concerts', ['_fragment' => $concertId]);
    }

    #[Route('/admin/concerts/{concertId}', name: 'admin_concert', requirements: ['concertId' => Requirement::UUID], methods: ['GET'])]
    public function detail(string $concertId): Response
    {
        return $this->onKnownConcert($concertId, fn (): Response => $this->concertPage($concertId));
    }

    #[Route('/admin/concerts/{concertId}/open-sales', name: 'admin_concert_open_sales', requirements: ['concertId' => Requirement::UUID], methods: ['POST'])]
    public function openSales(string $concertId): Response
    {
        return $this->onKnownConcert($concertId, fn (): Response => $this->sendToConcert($concertId, new OpenSales($concertId), 'Sales are open.'));
    }

    #[Route('/admin/concerts/{concertId}/price', name: 'admin_concert_price', requirements: ['concertId' => Requirement::UUID], methods: ['POST'])]
    public function changePrice(string $concertId, Request $request): Response
    {
        return $this->onKnownConcert($concertId, function () use ($concertId, $request): Response {
            $tier = Tier::tryFrom($request->request->getString('tier'));
            if ($tier === null) {
                return $this->concertPage($concertId, sprintf('There is no "%s" tier. Pick standard or vip.', $request->request->getString('tier')));
            }
            $priceCents = $request->request->getInt('priceCents');

            return $this->sendToConcert(
                $concertId,
                new ChangeTierPrice(concertId: $concertId, tier: $tier, priceCents: $priceCents),
                sprintf('The %s price is now %s %s.', $tier->value, number_format($priceCents / 100, 2, '.', ''), $this->concert($concertId)['currency']),
            );
        });
    }

    #[Route('/admin/concerts/{concertId}/cancel', name: 'admin_concert_cancel', requirements: ['concertId' => Requirement::UUID], methods: ['POST'])]
    public function cancel(string $concertId): Response
    {
        return $this->onKnownConcert($concertId, fn (): Response => $this->sendToConcert($concertId, new CancelConcert($concertId), 'The concert is cancelled.'));
    }

    #[Route('/concerts/{concertId}/seats', name: 'concert_seats', requirements: ['concertId' => Requirement::UUID], methods: ['GET'])]
    public function seats(string $concertId): Response
    {
        return $this->onKnownConcert($concertId, function () use ($concertId): Response {
            $seatMap = $this->queryBus->sendWithRouting('concert.seat_map', $concertId);
            $rows = [];
            foreach ($seatMap as $seat) {
                $rows[explode('-', $seat['seatCode'])[0]][] = $seat;
            }
            $tierBlocks = [];
            foreach ($rows as $row => $seats) {
                if ($tierBlocks === [] || $tierBlocks[array_key_last($tierBlocks)]['tier'] !== $seats[0]['tier']) {
                    $tierBlocks[] = ['tier' => $seats[0]['tier'], 'rows' => []];
                }
                $tierBlocks[array_key_last($tierBlocks)]['rows'][$row] = $seats;
            }

            return $this->render('concerts/seats.html.twig', [
                'concert' => $this->concert($concertId),
                'tierBlocks' => $tierBlocks,
                'seatCounts' => $this->seatCountsOf($seatMap),
                'refreshSeconds' => 3,
            ]);
        });
    }

    private function onKnownConcert(string $concertId, callable $action): Response
    {
        try {
            return $action();
        } catch (AggregateNotFoundException) {
            return $this->concertsPage(sprintf('There is no concert %s. Pick one from the list below.', $concertId), Response::HTTP_NOT_FOUND);
        }
    }

    private function sendToConcert(string $concertId, object $command, string $notice): Response
    {
        try {
            $this->commandBus->send($command);
        } catch (CatalogueRefusal $refusal) {
            return $this->concertPage($concertId, $refusal->getMessage());
        }

        $this->addFlash('notice', $notice);

        return $this->redirectToRoute('admin_concert', ['concertId' => $concertId]);
    }

    private function concertsPage(?string $refusal = null, int $refusalStatus = Response::HTTP_UNPROCESSABLE_ENTITY): Response
    {
        $concerts = $this->queryBus->sendWithRouting('concerts.list');

        return $this->render(
            'admin/concerts.html.twig',
            [
                'concerts' => $concerts,
                'seatCounts' => array_combine(
                    array_column($concerts, 'concertId'),
                    array_map(fn (array $concert): array => $this->seatCountsOf($this->queryBus->sendWithRouting('concert.seat_map', $concert['concertId'])), $concerts),
                ),
                'venues' => $this->queryBus->sendWithRouting('venues.list'),
                'refusal' => $refusal,
            ],
            new Response(status: $refusal === null ? Response::HTTP_OK : $refusalStatus),
        );
    }

    private function concertPage(string $concertId, ?string $refusal = null): Response
    {
        return $this->render(
            'admin/concert.html.twig',
            [
                'concert' => $this->concert($concertId),
                'sales' => $this->queryBus->sendWithRouting('concert.sales', $concertId),
                'seatCounts' => $this->seatCountsOf($this->queryBus->sendWithRouting('concert.seat_map', $concertId)),
                'refusal' => $refusal,
            ],
            new Response(status: $refusal === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    /**
     * @param list<array{status: string}> $seatMap
     * @return array{free: int, held: int, sold: int, total: int}
     */
    private function seatCountsOf(array $seatMap): array
    {
        $counts = ['free' => 0, 'held' => 0, 'sold' => 0];
        foreach ($seatMap as $seat) {
            $counts[$seat['status']]++;
        }

        return [...$counts, 'total' => count($seatMap)];
    }

    private function concert(string $concertId): array
    {
        return $this->queryBus->sendWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId]);
    }
}
