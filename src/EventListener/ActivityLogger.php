<?php

namespace App\EventListener;

use App\Entity\Activity;
use App\Entity\Collective;
use App\Entity\CollectiveScopedInterface;
use App\Entity\User;
use App\Enum\ActivityAction;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records an Activity whenever a collective, or a record belonging to one
 * (CollectiveScopedInterface), is created, updated or deleted.
 *
 * Changes are collected during the flush and written right after it with a
 * plain DBAL insert, so logging never triggers a nested ORM flush.
 * Only identifiers and changed field names are stored, never field values.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ActivityLogger implements ResetInterface
{
    /**
     * @var list<array{collective_id: int, actor_id: ?int, action: ActivityAction, subject_type: string, subject_id: int, changed_fields: list<string>}>
     */
    private array $pending = [];

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->record($args->getObjectManager(), $args->getObject(), ActivityAction::Created);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $changedFields = array_keys($args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity));

        $this->record($args->getObjectManager(), $entity, ActivityAction::Updated, $changedFields);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        // A deleted collective takes its whole log with it (ON DELETE CASCADE).
        if (!$args->getObject() instanceof Collective) {
            $this->record($args->getObjectManager(), $args->getObject(), ActivityAction::Deleted);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending) {
            return;
        }

        [$entries, $this->pending] = [$this->pending, []];
        $entityManager = $args->getObjectManager();
        $metadata = $entityManager->getClassMetadata(Activity::class);
        $connection = $entityManager->getConnection();
        $now = new \DateTimeImmutable();

        foreach ($entries as $entry) {
            $connection->insert($metadata->getTableName(), [
                $metadata->getSingleAssociationJoinColumnName('collective') => $entry['collective_id'],
                $metadata->getSingleAssociationJoinColumnName('actor') => $entry['actor_id'],
                $metadata->getColumnName('action') => $entry['action']->value,
                $metadata->getColumnName('subjectType') => $entry['subject_type'],
                $metadata->getColumnName('subjectId') => $entry['subject_id'],
                $metadata->getColumnName('changedFields') => $entry['changed_fields'],
                $metadata->getColumnName('occurredAt') => $now,
            ], [
                $metadata->getColumnName('changedFields') => Types::JSON,
                $metadata->getColumnName('occurredAt') => Types::DATETIME_IMMUTABLE,
            ]);
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    /**
     * @param list<string> $changedFields
     */
    private function record(EntityManagerInterface $entityManager, object $entity, ActivityAction $action, array $changedFields = []): void
    {
        if ($entity instanceof Activity) {
            return;
        }

        $collective = match (true) {
            $entity instanceof Collective => $entity,
            $entity instanceof CollectiveScopedInterface => $entity->getCollective(),
            default => null,
        };
        $subjectId = $entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity)['id'] ?? null;
        if (null === $collective?->getId() || !\is_int($subjectId)) {
            return;
        }

        $actor = $this->security->getUser();

        $this->pending[] = [
            'collective_id' => $collective->getId(),
            'actor_id' => $actor instanceof User ? $actor->getId() : null,
            'action' => $action,
            'subject_type' => new \ReflectionClass($entity)->getShortName(),
            'subject_id' => $subjectId,
            'changed_fields' => $changedFields,
        ];
    }
}
