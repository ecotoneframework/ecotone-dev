<?php

declare(strict_types=1);

namespace App\Controller;

use App\Connect\ConnectSnippets;
use App\Settings\PulseSettings;
use App\Snapshot\Snapshots;
use App\Ui\ConnectGuide;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConnectController extends AbstractController
{
    public function __construct(private readonly ConnectSnippets $snippets, private readonly PulseSettings $settings, private readonly Snapshots $snapshots)
    {
    }

    #[Route('/connect', name: 'connect', methods: ['GET'])]
    public function connect(Request $request): Response
    {
        $requested = trim($request->query->getString('service'));
        $service = $requested === '' ? ConnectSnippets::PLACEHOLDER_SERVICE : $requested;
        $known = array_keys($this->settings->routes);
        $framework = $request->query->getString('framework');

        if (! ConnectSnippets::isValidName($service)) {
            return $this->render('connect/index.html.twig', [
                'service' => ConnectSnippets::PLACEHOLDER_SERVICE,
                'invalid' => $service,
                'known' => $known,
                'snippets' => [],
                'servicesEntry' => '',
                'guide' => ConnectGuide::of(null, [], [ConnectGuide::DEFAULT_FRAMEWORK], $framework),
            ], new Response(status: Response::HTTP_BAD_REQUEST));
        }

        $snippets = $this->snippets->for($service);

        return $this->render('connect/index.html.twig', [
            'invalid' => null,
            'service' => $service,
            'known' => $known,
            'snippets' => $snippets,
            'servicesEntry' => $this->snippets->pulseServicesEntry($service),
            'requestChannel' => $this->snippets->requestChannel($service),
            'replyChannel' => $this->snippets->replyChannel($service),
            'pulseServiceName' => $this->settings->serviceName,
            'guide' => ConnectGuide::of($requested === '' ? null : $service, $this->snapshots->all(), array_keys($snippets), $framework),
        ]);
    }
}
