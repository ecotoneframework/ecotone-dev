<?php

declare(strict_types=1);

namespace App\Controller;

use App\Connect\ConnectSnippets;
use App\Settings\PulseSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConnectController extends AbstractController
{
    public function __construct(private readonly ConnectSnippets $snippets, private readonly PulseSettings $settings)
    {
    }

    #[Route('/connect', name: 'connect', methods: ['GET'])]
    public function connect(Request $request): Response
    {
        $service = trim($request->query->getString('service'));
        $service = $service === '' ? ConnectSnippets::PLACEHOLDER_SERVICE : $service;

        if (! ConnectSnippets::isValidName($service)) {
            return $this->render('connect/index.html.twig', ['service' => ConnectSnippets::PLACEHOLDER_SERVICE, 'invalid' => $service, 'known' => array_keys($this->settings->routes), 'snippets' => [], 'servicesEntry' => ''], new Response(status: Response::HTTP_BAD_REQUEST));
        }

        return $this->render('connect/index.html.twig', [
            'invalid' => null,
            'service' => $service,
            'known' => array_keys($this->settings->routes),
            'snippets' => $this->snippets->for($service),
            'servicesEntry' => $this->snippets->pulseServicesEntry($service),
        ]);
    }
}
