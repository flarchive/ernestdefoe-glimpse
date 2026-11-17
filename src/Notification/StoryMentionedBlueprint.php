<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Notification;

use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\MailableInterface;

/** "X mentioned you in their story." Alert and email. */
class StoryMentionedBlueprint extends StoryBlueprint implements MailableInterface
{
    public static function getType(): string
    {
        return 'glimpseMentioned';
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-glimpse::emails.plain.mentioned',
            'html' => 'ernestdefoe-glimpse::emails.html.mentioned',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-glimpse.email.mentioned.subject', ['{actor}' => $this->from->display_name]);
    }
}
