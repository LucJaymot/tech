<?php

namespace App\Collective;

use App\Doctrine\CollectiveFilter;
use App\Entity\Collective;
use App\Entity\User;
use App\Repository\MembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds the collective the current user works in, stored in session, and keeps
 * the "collective" Doctrine filter pointed at it.
 */
final class ActiveCollective implements ResetInterface
{
    public const string SESSION_KEY = '_active_collective';

    private ?int $collectiveId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MembershipRepository $memberships,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Resolves the active collective for the current user and enables the filter.
     * Falls back to the user's first collective when the session holds none or
     * one they no longer belong to.
     */
    public function initialize(): void
    {
        $this->collectiveId = null;
        $user = $this->security->getUser();

        if ($user instanceof User) {
            $session = $this->requestStack->getSession();
            $requested = $session->get(self::SESSION_KEY);

            $this->collectiveId = $this->withoutFilter(function () use ($user, $requested): ?int {
                if (\is_int($requested) && $this->memberships->isMember($user, $requested)) {
                    return $requested;
                }

                return ($this->memberships->findCollectiveChoicesForUser($user)[0] ?? null)?->id;
            });

            if ($this->collectiveId !== $requested) {
                null === $this->collectiveId ? $session->remove(self::SESSION_KEY) : $session->set(self::SESSION_KEY, $this->collectiveId);
            }
        }

        $this->applyFilter();
    }

    /**
     * Forgets the active collective and disables the filter.
     */
    public function reset(): void
    {
        $this->collectiveId = null;

        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled(CollectiveFilter::NAME)) {
            $filters->disable(CollectiveFilter::NAME);
        }
    }

    /**
     * Makes $collective active if $user is a member of it.
     */
    public function switchTo(User $user, Collective|int $collective): bool
    {
        if (!$this->withoutFilter(fn (): bool => $this->memberships->isMember($user, $collective))) {
            return false;
        }

        $this->collectiveId = $collective instanceof Collective ? $collective->getId() : $collective;
        $this->requestStack->getSession()->set(self::SESSION_KEY, $this->collectiveId);
        $this->applyFilter();

        return true;
    }

    public function getId(): ?int
    {
        return $this->collectiveId;
    }

    public function get(): ?Collective
    {
        return null === $this->collectiveId ? null : $this->entityManager->find(Collective::class, $this->collectiveId);
    }

    /**
     * Every collective $user belongs to, as plain arrays so that nothing outside
     * the active collective enters the identity map.
     *
     * @return list<CollectiveChoice>
     */
    public function getChoices(User $user): array
    {
        return $this->withoutFilter(fn (): array => $this->memberships->findCollectiveChoicesForUser($user));
    }

    /**
     * Runs $callback with the "collective" filter disabled. Keep its results
     * out of anything shown to the user unless they are explicitly allowed.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withoutFilter(callable $callback): mixed
    {
        $filters = $this->entityManager->getFilters();
        if (!$filters->isEnabled(CollectiveFilter::NAME)) {
            return $callback();
        }

        $filters->disable(CollectiveFilter::NAME);
        try {
            return $callback();
        } finally {
            $this->applyFilter();
        }
    }

    private function applyFilter(): void
    {
        $filter = $this->entityManager->getFilters()->enable(CollectiveFilter::NAME);
        if (null !== $this->collectiveId) {
            $filter->setParameter(CollectiveFilter::PARAMETER, $this->collectiveId, Types::INTEGER);
        }
    }
}
