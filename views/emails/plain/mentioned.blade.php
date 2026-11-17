<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-glimpse.email.mentioned.body', [
'{actor}' => $blueprint->from->display_name,
'{url}' => $url->to('forum')->route('glimpse.story', ['id' => $blueprint->story->id]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
