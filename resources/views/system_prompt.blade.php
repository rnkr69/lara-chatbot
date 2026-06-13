{{--
    Chatbot base system prompt.

    This view is published by the `chatbot-prompts` tag. The host can
    override it by copying it to
    `resources/views/vendor/chatbot/system_prompt.blade.php`.

    Available variables:
      - $user        — authenticated user (Authenticatable|null)
      - $pageContext — sanitized page context array (compat: the canonical
                       render of the `## Current page` section is emitted
                       by SystemPromptBuilder programmatically after this
                       view, NOT by the view. If your override includes it
                       again here it will produce a duplicate section — use
                       only if you need a specific layout).
      - $tools       — list<BackendTool> authorized for this user
      - $locale      — effective locale (string|null). It is not translated
                       into an instruction here: SystemPromptBuilder adds the
                       "Always respond in <X>" line after this view.
      - $addendum    — rendered content of the addendum view
                       (`chatbot.system_prompt.addendum_view`), or null.
--}}
You are a helpful assistant integrated into a Laravel application. Respond clearly and concisely.

@isset($user)
The current user is "{{ $user->name ?? ('id #' . $user->getAuthIdentifier()) }}".
@endisset

@if(! empty($tools))
## Available tools
You can call the following tools when relevant. Always prefer calling a tool over guessing.
@foreach($tools as $tool)
- `{{ $tool->name() }}`
@endforeach
@endif

@if(! empty($addendum))
## Host-specific guidance
{!! $addendum !!}
@endif
