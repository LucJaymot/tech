<?php

namespace App\Controller;

use App\Visuel\FormatVisuel;
use App\Visuel\PagesPubliques;
use App\Visuel\VisuelsLineUp;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, without authentication: anyone with the slug sees the soirée's
 * name, date, venue and line-up, never anything else (App\Visuel\VuePublique).
 */
final class PagePubliqueController extends AbstractController
{
    public function __construct(
        private readonly PagesPubliques $pages,
    ) {
    }

    #[Route('/s/{slug}', name: 'app_soiree_publique', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $slug): Response
    {
        $vue = $this->pages->vue($slug) ?? throw $this->createNotFoundException();

        return $this->render('page_publique/show.html.twig', ['vue' => $vue]);
    }

    #[Route('/s/{slug}/visuel-{format}.svg', name: 'app_soiree_publique_visuel', requirements: ['slug' => '[a-z0-9-]+', 'format' => 'carre|story'], methods: ['GET'])]
    public function visuel(string $slug, FormatVisuel $format, VisuelsLineUp $visuels): Response
    {
        $soiree = $this->pages->soiree($slug) ?? throw $this->createNotFoundException();
        $flux = $visuels->lire($soiree, $format) ?? throw $this->createNotFoundException('Not generated yet.');

        $response = new StreamedResponse(static function () use ($flux): void {
            fpassthru($flux);
            fclose($flux);
        });
        $response->headers->set('Content-Type', 'image/svg+xml');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Opened on its own, an SVG is a document: forbid scripts and outside resources.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
