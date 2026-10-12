<?php

namespace App\Entity;

use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * A user's membership in a collective, with their role in it.
 */
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_MEMBERSHIP_USER_COLLECTIVE', fields: ['user', 'collective'])]
#[UniqueEntity(fields: ['user', 'collective'], message: 'Cette personne est déjà membre du collectif.')]
class Membership implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\Column(length: 20, enumType: MembershipRole::class)]
    private MembershipRole $role;

    #[ORM\Column]
    private \DateTimeImmutable $joinedAt;

    public function __construct(User $user, Collective $collective, MembershipRole $role = MembershipRole::Member)
    {
        $this->user = $user;
        $this->collective = $collective;
        $this->role = $role;
        $this->joinedAt = new \DateTimeImmutable();

        $user->getMemberships()->add($this);
        $collective->getMemberships()->add($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }

    public function getRole(): MembershipRole
    {
        return $this->role;
    }

    public function setRole(MembershipRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
