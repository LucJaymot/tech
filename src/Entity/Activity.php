<?php

namespace App\Entity;

use App\Enum\ActivityAction;
use App\Repository\ActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry of a collective's activity log: who did what to which record, and when.
 *
 * Written by App\EventListener\ActivityLogger. It only references the actor
 * and the record and lists the names of changed fields, never their values,
 * so no personal data is copied into the log.
 */
#[ORM\Entity(repositoryClass: ActivityRepository::class, readOnly: true)]
#[ORM\Index(name: 'IDX_ACTIVITY_COLLECTIVE_OCCURRED_AT', fields: ['collective', 'occurredAt'])]
class Activity implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    /** Null for system changes (console, fixtures) or once the user is deleted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(length: 20, enumType: ActivityAction::class)]
    private ActivityAction $action;

    /** Short class name of the record, e.g. "Membership". */
    #[ORM\Column(length: 50)]
    private string $subjectType;

    #[ORM\Column]
    private int $subjectId;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $changedFields;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param list<string> $changedFields
     */
    public function __construct(Collective $collective, ?User $actor, ActivityAction $action, string $subjectType, int $subjectId, array $changedFields = [], ?\DateTimeImmutable $occurredAt = null)
    {
        $this->collective = $collective;
        $this->actor = $actor;
        $this->action = $action;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->changedFields = $changedFields;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getAction(): ActivityAction
    {
        return $this->action;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    /**
     * @return list<string>
     */
    public function getChangedFields(): array
    {
        return $this->changedFields;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
