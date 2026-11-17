<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use ErnestDefoe\Glimpse\Highlight;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\StoryMedia;
use ErnestDefoe\Glimpse\StoryMute;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 Some drivers (PDO emulated prepares, some MySQL/MariaDB setups) return ids as strings. The policies
 * compare owner ids with ===, so the models cast them: a string from the database still means the owner.
 */
class IdCastTest extends GlimpseTestCase
{
    protected bool $follows = false;

    #[Test]
    public function an_owner_id_read_back_as_a_string_still_matches_its_owner(): void
    {
        $this->send($this->request('GET', '/api')); // boot the app (policies registered)
        $owner = User::query()->findOrFail(2);
        $stranger = User::query()->findOrFail(4);

        // Exactly what a string-returning driver hands Eloquent.
        $story = (new Story())->newFromBuilder(['id' => '1', 'user_id' => '2', 'media_id' => '7', 'kind' => 'text', 'audience' => 'everyone', 'status' => 'live']);
        $highlight = (new Highlight())->newFromBuilder(['id' => '1', 'user_id' => '2', 'position' => '1']);

        $this->assertSame(2, $story->user_id);
        $this->assertSame(7, $story->media_id);
        $this->assertSame(2, $highlight->user_id);
        $this->assertSame(2, (new StoryMedia())->newFromBuilder(['user_id' => '2'])->user_id);
        $this->assertSame([2, 3], array_values((new StoryMute())->newFromBuilder(['user_id' => '2', 'muted_user_id' => '3'])->only('user_id', 'muted_user_id')));

        $this->assertTrue($owner->can('delete', $story), 'the author deletes their story');
        $this->assertTrue($owner->can('seeViewers', $story));
        $this->assertFalse($owner->can('report', $story), 'nobody reports their own story');
        $this->assertTrue($owner->can('update', $highlight), 'the owner edits their highlight');
        $this->assertTrue($owner->can('delete', $highlight));
        $this->assertFalse($stranger->can('update', $highlight));
        $this->assertTrue($stranger->can('report', $story));
    }
}
