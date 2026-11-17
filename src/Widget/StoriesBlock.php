<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Widget;

use Ernestdefoe\PageBuilder\Block\BlockInterface;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;

/**
 * The ring bar as a Page Builder block. It hands Page Builder nothing to render server-side: the
 * client loads the bar from /api/glimpse-stories like every other place it appears, under the same rules.
 * Registered from extend.php only when Page Builder is enabled.
 */
class StoriesBlock implements BlockInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function type(): string
    {
        return 'glimpse-bar';
    }

    public function name(): string
    {
        return $this->translator->trans('ernestdefoe-glimpse.forum.widget.title');
    }

    public function icon(): string
    {
        return 'fas fa-circle-play';
    }

    public function category(): string
    {
        return 'content';
    }

    public function defaultSettings(): array
    {
        return [];
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function resolve(array $settings, User $actor): array
    {
        return [];
    }
}
