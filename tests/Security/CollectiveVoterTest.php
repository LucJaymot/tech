<?php

namespace App\Tests\Security;

use App\Entity\Collective;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use App\Security\Voter\CollectiveVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CollectiveVoterTest extends TestCase
{
    /**
     * @return iterable<string, array{MembershipRole, string, int}>
     */
    public static function permissions(): iterable
    {
        $granted = VoterInterface::ACCESS_GRANTED;
        $denied = VoterInterface::ACCESS_DENIED;

        yield 'admin manages members' => [MembershipRole::Admin, CollectiveVoter::MANAGE_MEMBERS, $granted];
        yield 'admin edits' => [MembershipRole::Admin, CollectiveVoter::EDIT, $granted];
        yield 'admin views budget' => [MembershipRole::Admin, CollectiveVoter::VIEW_BUDGET, $granted];
        yield 'member cannot manage members' => [MembershipRole::Member, CollectiveVoter::MANAGE_MEMBERS, $denied];
        yield 'member edits' => [MembershipRole::Member, CollectiveVoter::EDIT, $granted];
        yield 'member views budget' => [MembershipRole::Member, CollectiveVoter::VIEW_BUDGET, $granted];
        yield 'reader cannot manage members' => [MembershipRole::Reader, CollectiveVoter::MANAGE_MEMBERS, $denied];
        yield 'reader cannot edit' => [MembershipRole::Reader, CollectiveVoter::EDIT, $denied];
        yield 'reader views budget' => [MembershipRole::Reader, CollectiveVoter::VIEW_BUDGET, $granted];
    }

    #[DataProvider('permissions')]
    public function testRolePermissions(MembershipRole $role, string $attribute, int $expected): void
    {
        $user = new User('a@example.com', 'A');
        $collective = new Collective('Collectif', 'collectif');

        self::assertSame($expected, $this->voter(new Membership($user, $collective, $role))->vote($this->token($user), $collective, [$attribute]));
    }

    public function testNonMemberIsDeniedEverything(): void
    {
        $user = new User('a@example.com', 'A');
        $collective = new Collective('Collectif', 'collectif');
        $voter = $this->voter(null);

        foreach ([CollectiveVoter::MANAGE_MEMBERS, CollectiveVoter::EDIT, CollectiveVoter::VIEW_BUDGET] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($user), $collective, [$attribute]));
        }
    }

    public function testAnonymousIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter(null)->vote(new NullToken(), new Collective('Collectif', 'collectif'), [CollectiveVoter::VIEW_BUDGET]));
    }

    public function testAbstainsOnOtherAttributesAndSubjects(): void
    {
        $user = new User('a@example.com', 'A');
        $voter = $this->voter(null);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token($user), new Collective('Collectif', 'collectif'), ['ROLE_ADMIN']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token($user), new \stdClass(), [CollectiveVoter::EDIT]));
    }

    private function voter(?Membership $membership): CollectiveVoter
    {
        $memberships = $this->createStub(MembershipRepository::class);
        $memberships->method('findOneBy')->willReturn($membership);

        return new CollectiveVoter($memberships);
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
