<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use ErnestDefoe\Glimpse\Enum\Audience;
use ErnestDefoe\Glimpse\Enum\Kind;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Glimpse's settings, typed and clamped to sane ranges. The defaults are registered once, in
 * extend.php's Extend\Settings; this class only reads and bounds what is stored.
 */
class Config
{
    public const PREFIX = 'ernestdefoe-glimpse.';

    /** The lifetimes the admin may pick, in hours. */
    public const LIFETIMES = [6, 12, 24, 48, 168];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function lifetimeHours(): int
    {
        $hours = $this->int('lifetime_hours');

        // A value the admin page can't produce: the nearest lifetime it offers.
        foreach (self::LIFETIMES as $allowed) {
            if ($hours <= $allowed) {
                return $allowed;
            }
        }

        return self::LIFETIMES[count(self::LIFETIMES) - 1];
    }

    public function allows(Kind $kind): bool
    {
        return (bool) $this->settings->get(self::PREFIX.'kind_'.$kind->value);
    }

    public function slideSeconds(): int
    {
        return $this->clamp($this->int('slide_seconds'), 2, 20);
    }

    /** index, index_tags, page or none */
    public function barPlacement(): string
    {
        $placement = $this->string('bar_placement');

        return in_array($placement, ['index', 'index_tags', 'page', 'none'], true) ? $placement : 'index';
    }

    /** smart or newest */
    public function barOrder(): string
    {
        return $this->string('bar_order') === 'newest' ? 'newest' : 'smart';
    }

    /** auto, parley, messages or off */
    public function replyVia(): string
    {
        $via = $this->string('reply_via');

        return in_array($via, ['auto', 'parley', 'messages', 'off'], true) ? $via : 'auto';
    }

    public function imageMaxBytes(): int
    {
        return $this->clamp($this->int('image_max_mb'), 1, 50) * 1024 * 1024;
    }

    public function videoMaxBytes(): int
    {
        return $this->clamp($this->int('video_max_mb'), 1, 200) * 1024 * 1024;
    }

    public function videoMaxSeconds(): int
    {
        return $this->clamp($this->int('video_max_seconds'), 3, 60);
    }

    public function defaultAudience(): Audience
    {
        return Audience::tryFrom($this->string('default_audience')) ?? Audience::Everyone;
    }

    public function ignoreHidesMine(): bool
    {
        return (bool) $this->settings->get(self::PREFIX.'ignore_hides');
    }

    /** @return list<string> the quick reactions, in order */
    public function reactions(): array
    {
        $list = preg_split('/\s+/u', trim($this->string('reactions')), -1, PREG_SPLIT_NO_EMPTY);
        $list = array_values(array_unique(array_filter(
            $list === false ? [] : $list,
            fn (string $r): bool => mb_strlen($r) <= 8 && strlen($r) <= 16 && preg_match('/[\p{L}\p{N}<>"&]/u', $r) !== 1
        )));

        return $list === [] ? ['❤️'] : array_slice($list, 0, 8);
    }

    /** Seconds a member waits between uploads or stories; 0 turns the guard off. */
    public function floodSeconds(): int
    {
        return $this->clamp($this->int('flood_seconds'), 0, 300);
    }

    public function approvalPosts(): int
    {
        return max(0, $this->int('approval_posts'));
    }

    public function approvalDays(): int
    {
        return max(0, $this->int('approval_days'));
    }

    public function approvalTrustLevel(): int
    {
        return $this->clamp($this->int('approval_trust_level'), 0, 4);
    }

    public function notifyFollowers(): bool
    {
        return (bool) $this->settings->get(self::PREFIX.'notify_followers');
    }

    public function notifyCap(): int
    {
        return $this->clamp($this->int('notify_cap'), 1, 5000);
    }

    public function dailyLimit(): int
    {
        return $this->clamp($this->int('daily_limit'), 1, 200);
    }

    public function highlightsMax(): int
    {
        return $this->clamp($this->int('highlights_max'), 1, 100);
    }

    public function highlightStoriesMax(): int
    {
        return $this->clamp($this->int('highlight_stories_max'), 1, 500);
    }

    private function string(string $key): string
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return is_string($value) ? $value : '';
    }

    private function int(string $key): int
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
