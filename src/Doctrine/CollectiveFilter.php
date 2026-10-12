<?php

namespace App\Doctrine;

use App\Entity\Collective;
use App\Entity\CollectiveScopedInterface;
use App\Entity\User;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Restricts ORM queries to the active collective:
 * - scoped entities (CollectiveScopedInterface) to rows of that collective;
 * - collectives to the active one;
 * - users to members of the active collective.
 *
 * Without an active collective, these entities return no rows at all.
 * Raw DBAL queries are not filtered, and entities already in the identity map
 * are returned by find() without a new query.
 */
final class CollectiveFilter extends SQLFilter
{
    public const string NAME = 'collective';
    public const string PARAMETER = 'collective_id';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $class = $targetEntity->getReflectionClass();

        if ($class->implementsInterface(CollectiveScopedInterface::class)) {
            $condition = '%1$s.'.$targetEntity->getSingleAssociationJoinColumnName('collective').' = %2$s';
        } elseif (Collective::class === $class->getName()) {
            $condition = '%1$s.id = %2$s';
        } elseif (User::class === $class->getName()) {
            $condition = 'EXISTS (SELECT 1 FROM membership collective_filter_m WHERE collective_filter_m.user_id = %1$s.id AND collective_filter_m.collective_id = %2$s)';
        } else {
            return '';
        }

        if (!$this->hasParameter(self::PARAMETER)) {
            return '1 = 0';
        }

        return \sprintf($condition, $targetTableAlias, $this->getParameter(self::PARAMETER));
    }
}
