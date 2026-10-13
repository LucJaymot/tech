<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    #[Route('/a-propos', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('home/about.html.twig');
    }

    /** Shown by public/sw.js when a page cannot be loaded. */
    #[Route('/hors-ligne', name: 'app_hors_ligne', methods: ['GET'])]
    public function horsLigne(): Response
    {
        return $this->render('home/hors_ligne.html.twig');
    }
}
