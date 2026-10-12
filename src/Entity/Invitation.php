<?php

namespace App\Entity;

use App\Enum\MembershipRole;
use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An invitation, sent by e-mail, to join a collective with a given role.
 * Only a hash of the token is stored; the token itself is only in the e-mail.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_INVITATION_TOKEN_HASH', fields: ['tokenHash'])]
#[ORM\Index(name: 'IDX_INVITATION_EMAIL', fields: ['email'])]
class Invitation implements CollectiveScopedInterface
{
    public const string LIFETIME = '+7 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 20, enumType: MembershipRole::class)]
    private MembershipRole $role;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $invitedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    public function __construct(Collective $collective, string $email)
    {
        $this->collective = $collective;
        $this->email = mb_strtolower(trim($email));
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Sets the role and inviter, and issues a new token valid for LIFETIME.
     * Previously issued tokens stop working.
     *
     * @return string the plain token, to put in the invitation link
     */
    public function issue(MembershipRole $role, User $invitedBy): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->role = $role;
        $this->invitedBy = $invitedBy;
        $this->tokenHash = self::hashToken($token);
        $this->expiresAt = new \DateTimeImmutable(self::LIFETIME);

        return $token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isPending(): bool
    {
        return null === $this->acceptedAt && $this->expiresAt > new \DateTimeImmutable();
    }

    public function markAccepted(): void
    {
        $this->acceptedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): MembershipRole
    {
        return $this->role;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }
}
