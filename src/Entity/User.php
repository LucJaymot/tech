<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string|null The hashed password; null for passwordless accounts
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom ne peut pas être vide.')]
    #[Assert\Length(max: 100)]
    private string $displayName;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Set by App\EventListener\DerniereConnexionListener; null before the first login. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $derniereConnexionLe = null;

    /** Inactive account warned of its deletion on that date (App\Rgpd\ComptesInactifs). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suppressionPrevueLe = null;

    /**
     * @var Collection<int, Membership>
     */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $memberships;

    public function __construct(string $email, string $displayName)
    {
        $this->email = $email;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('A user must have an e-mail address.');
        }

        return $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDerniereConnexionLe(): ?\DateTimeImmutable
    {
        return $this->derniereConnexionLe;
    }

    /**
     * A login cancels any planned deletion.
     */
    public function seConnecte(\DateTimeImmutable $le): void
    {
        $this->derniereConnexionLe = $le;
        $this->suppressionPrevueLe = null;
    }

    public function getSuppressionPrevueLe(): ?\DateTimeImmutable
    {
        return $this->suppressionPrevueLe;
    }

    public function prevoirSuppression(\DateTimeImmutable $le): void
    {
        $this->suppressionPrevueLe = $le;
    }

    /**
     * @return Collection<int, Membership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = null === $this->password ? null : hash('crc32c', $this->password);

        return $data;
    }
}
