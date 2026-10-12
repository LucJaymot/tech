<?php

namespace App\Twig\Components;

use App\Collective\ActiveCollective;
use App\Collective\CollectiveChoice;
use App\Entity\Collective;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Header showing the active collective, with a selector when the user belongs
 * to several.
 */
#[AsTwigComponent]
final class CollectiveSwitcher
{
    public function __construct(
        private readonly ActiveCollective $activeCollective,
        private readonly Security $security,
    ) {
    }

    public function getActive(): ?Collective
    {
        return $this->activeCollective->get();
    }

    /**
     * @return list<CollectiveChoice>
     */
    public function getChoices(): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->activeCollective->getChoices($user) : [];
    }
}
