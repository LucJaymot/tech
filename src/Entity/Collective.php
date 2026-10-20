<?php

namespace App\Entity;

use App\Enum\StyleTitres;
use App\Repository\CollectiveRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CollectiveRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_COLLECTIVE_SLUG', fields: ['slug'])]
#[UniqueEntity(fields: ['slug'])]
class Collective
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
    private string $slug;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Accent colour of its line-up visuals, "#rrggbb". */
    #[ORM\Column(length: 7, options: ['default' => '#6e8bff'])]
    #[Assert\Regex('/^#[0-9a-f]{6}$/', message: 'Choisissez une couleur au format #rrggbb.')]
    private string $couleurAccent = '#6e8bff';

    /**
     * Subscription plan. Nothing depends on it until a price is decided.
     */
    #[ORM\Column(length: 20, options: ['default' => 'gratuit'])]
    private string $plan = 'gratuit';

    /** Key of its PNG or JPEG logo in the "visuels" storage, managed by App\Visuel\IdentiteVisuelle. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    /** Typeface of its page titles, in the app and on its public pages. */
    #[ORM\Column(length: 10, enumType: StyleTitres::class, options: ['default' => 'grotesk'])]
    private StyleTitres $styleTitres = StyleTitres::Grotesk;

    /**
     * @var Collection<int, Membership>
     */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'collective', orphanRemoval: true)]
    private Collection $memberships;

    public function __construct(string $name, string $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCouleurAccent(): string
    {
        return $this->couleurAccent;
    }

    public function setCouleurAccent(string $couleurAccent): static
    {
        $this->couleurAccent = mb_strtolower($couleurAccent);

        return $this;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getStyleTitres(): StyleTitres
    {
        return $this->styleTitres;
    }

    public function setStyleTitres(StyleTitres $styleTitres): static
    {
        $this->styleTitres = $styleTitres;

        return $this;
    }

    /**
     * @return Collection<int, Membership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }
}
