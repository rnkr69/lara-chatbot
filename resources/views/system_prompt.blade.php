{{--
    System prompt base del chatbot.

    Esta vista la publica el tag `chatbot-prompts`. El host puede
    sobrescribirla copiándola a
    `resources/views/vendor/chatbot/system_prompt.blade.php`.

    Variables disponibles:
      - $user        — usuario autenticado (Authenticatable|null)
      - $pageContext — array sanitizado del page context (compat: el render
                       canónico de la sección `## Current page` lo emite
                       SystemPromptBuilder programáticamente tras esta
                       vista, NO la vista. Si tu override la incluye otra
                       vez aquí dará lugar a una sección duplicada — usa
                       sólo si necesitas un layout específico).
      - $tools       — list<BackendTool> autorizadas para este usuario
      - $locale      — locale efectivo (string|null). Aquí no se traduce a
                       instrucción: SystemPromptBuilder añade la línea
                       "Always respond in <X>" después de esta vista.
      - $addendum    — contenido renderizado de la vista addendum
                       (`chatbot.system_prompt.addendum_view`), o null.
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
