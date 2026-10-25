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

    #[Route('/admin/concerts/{concertId}', name: 'admin_concert', methods: ['GET'])]
    public function detail(string $concertId): Response
    {
        return $this->concertPage($concertId);
    }

    #[Route('/admin/concerts/{concertId}/open-sales', name: 'admin_concert_open_sales', methods: ['POST'])]
    public function openSales(string $concertId): Response
    {
        return $this->onConcert($concertId, new OpenSales($concertId), 'Sales are open.');
    }

    #[Route('/admin/concerts/{concertId}/price', name: 'admin_concert_price', methods: ['POST'])]
    public function changePrice(string $concertId, Request $request): Response
    {
        $tier = Tier::tryFrom($request->request->getString('tier'));
        if ($tier === null) {
            return $this->concertPage($concertId, sprintf('There is no "%s" tier. Pick standard or vip.', $request->request->getString('tier')));
        }
        $priceCents = $request->request->getInt('priceCents');
        $concert = $this->concert($concertId);

        return $this->onConcert(
            $concertId,
            new ChangeTierPrice(concertId: $concertId, tier: $tier, priceCents: $priceCents),
            sprintf('The %s price is now %s %s.', $tier->value, number_format($priceCents / 100, 2, '.', ''), $concert['currency']),
        );
    }

    #[Route('/admin/concerts/{concertId}/cancel', name: 'admin_concert_cancel', methods: ['POST'])]
    public function cancel(string $concertId): Response
    {
        return $this->onConcert($concertId, new CancelConcert($concertId), 'The concert is cancelled.');
    }

    private function onConcert(string $concertId, object $command, string $notice): Response
    {
        try {
            $this->commandBus->send($command);
        } catch (CatalogueRefusal $refusal) {
            return $this->concertPage($concertId, $refusal->getMessage());
        }

        $this->addFlash('notice', $notice);

        return $this->redirectToRoute('admin_concert', ['concertId' => $concertId]);
    }

    private function concertsPage(?string $refusal = null): Response
    {
        return $this->render(
            'admin/concerts.html.twig',
            [
                'concerts' => $this->queryBus->sendWithRouting('concerts.list'),
                'venues' => $this->queryBus->sendWithRouting('venues.list'),
                'refusal' => $refusal,
            ],
            new Response(status: $refusal === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    private function concertPage(string $concertId, ?string $refusal = null): Response
    {
        return $this->render(
            'admin/concert.html.twig',
            ['concert' => $this->concert($concertId), 'refusal' => $refusal],
            new Response(status: $refusal === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    private function concert(string $concertId): array
    {
        try {
            return $this->queryBus->sendWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId]);
        } catch (AggregateNotFoundException) {
            throw $this->createNotFoundException(sprintf('There is no concert %s. Pick one from the list under /admin/concerts.', $concertId));
        }
    }
}
