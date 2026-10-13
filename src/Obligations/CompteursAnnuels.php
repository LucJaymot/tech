<?php

namespace App\Obligations;

use App\Entity\Collective;
use App\Repository\SoireeRepository;

final class CompteursAnnuels
{
    public function __construct(
        private readonly Obligations $obligations,
        private readonly SoireeRepository $soirees,
    ) {
    }

    /**
     * @return list<Compteur> one per yearly limit, over calendar year $annee
     */
    public function pour(Collective $collective, int $annee): array
    {
        return array_map(
            fn (SeuilAnnuel $seuil): Compteur => new Compteur($seuil, $annee, $this->soirees->compterDansLAnnee($collective, $annee, $seuil->propriete())),
            $this->obligations->seuils(),
        );
    }
}
