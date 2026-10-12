<?php

namespace App\Collective;

use App\Enum\MembershipRole;

/**
 * A collective the user belongs to, as offered by the collective switcher.
 * Plain values, so that collectives other than the active one never enter the
 * Doctrine identity map.
 */
final readonly class CollectiveChoice
{
    public MembershipRole $role;

    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        MembershipRole|string $role,
    ) {
        $this->role = $role instanceof MembershipRole ? $role : MembershipRole::from($role);
    }
}
