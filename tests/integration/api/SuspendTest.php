<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/** With flarum/suspend: a suspended author's stories are hidden while the suspension lasts. */
class SuspendTest extends GlimpseTestCase
{
    protected bool $follows = false;

    protected array $alsoEnable = ['flarum-suspend'];

    #[Test]
    public function a_suspended_authors_stories_disappear_while_suspended(): void
    {
        $suspended = $this->insert(2);
        $ended = $this->insert(3);
        $this->database()->table('users')->where('id', 2)->update(['suspended_until' => Carbon::now()->addDay()]);
        $this->database()->table('users')->where('id', 3)->update(['suspended_until' => Carbon::now()->subDay()]);

        $this->assertSame([$ended], $this->ids(4));
        $this->assertSame(404, $this->get("/api/glimpse-stories/$suspended", 4)->getStatusCode());
        // Moderators still see it, to deal with it.
        $this->assertEqualsCanonicalizing([$suspended, $ended], $this->ids(5));
    }
}
