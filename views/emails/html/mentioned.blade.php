<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-glimpse.email.mentioned.body', [
            '{actor}' => $blueprint->from->display_name,
            '{url}' => $url->to('forum')->route('glimpse.story', ['id' => $blueprint->story->id]),
        ])) !!}
    </x-slot:body>

    <x-slot:preview>
        {{ $blueprint->story->caption ?? $blueprint->story->text ?? '' }}
    </x-slot:preview>
</x-mail::html.notification>
