<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\unit;

use ErnestDefoe\Glimpse\Approval\TrustLevelApproval;
use ErnestDefoe\Glimpse\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The trust level is read from wherever the installed Trust Levels keeps it. */
class TrustLevelApprovalTest extends TestCase
{
    /** Whether a member at these attributes is still new, with approval below trust level 2 only. */
    private function isNew(User $user): bool
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(fn (string $key) => $key === 'ernestdefoe-glimpse.approval_trust_level' ? '2' : null);
        $approval = new TrustLevelApproval(new Config($settings));

        return (fn (User $actor): bool => $this->isNew($actor))->call($approval, $user);
    }

    private function member(array $attributes): User
    {
        $user = new User();
        $user->forceFill($attributes + ['id' => 7, 'comment_count' => 50]);

        return $user;
    }

    #[Test]
    public function an_older_trust_levels_keeps_the_level_on_the_user(): void
    {
        $this->assertFalse(class_exists('ErnestDefoe\\TrustLevels\\TrustState'));
        $this->assertTrue($this->isNew($this->member(['trust_level' => 1])));
        $this->assertFalse($this->isNew($this->member(['trust_level' => 2])));
        $this->assertTrue($this->isNew($this->member([])), 'No level yet: new.');
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_newer_trust_levels_keeps_it_in_its_own_state(): void
    {
        require_once __DIR__.'/../stubs/TrustState.php';

        // The old column says otherwise each time: the state wins.
        $this->assertTrue($this->isNew($this->member(['state_level' => 1, 'trust_level' => 4])));
        $this->assertFalse($this->isNew($this->member(['state_level' => 3, 'trust_level' => 0])));
        $this->assertTrue($this->isNew($this->member(['trust_level' => 4])), 'No state row: level 0.');
    }
}
