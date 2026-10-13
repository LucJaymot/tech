<?php

namespace App\Entity;

use App\Repository\AbonnementPushRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A device (browser) of a user that accepted push notifications. The push
 * service identifies it by its endpoint.
 */
#[ORM\Entity(repositoryClass: AbonnementPushRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_ABONNEMENT_PUSH_ENDPOINT', fields: ['endpoint'])]
class AbonnementPush
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::TEXT)]
    private string $endpoint;

    /** Public key of the device, base64url. */
    #[ORM\Column(length: 255)]
    private string $cleP256dh;

    /** Authentication secret of the device, base64url. */
    #[ORM\Column(length: 255)]
    private string $cleAuth;

    /** User-Agent at subscription, to tell devices apart. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $appareil;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(User $user, string $endpoint, string $cleP256dh, string $cleAuth, ?string $appareil)
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->cleP256dh = $cleP256dh;
        $this->cleAuth = $cleAuth;
        $this->appareil = null === $appareil ? null : mb_substr($appareil, 0, 255);
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getCleP256dh(): string
    {
        return $this->cleP256dh;
    }

    public function getCleAuth(): string
    {
        return $this->cleAuth;
    }

    public function getAppareil(): ?string
    {
        return $this->appareil;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
