{{--
    EXAMPLE of a system prompt addendum view.

    This is NOT the view published by default. The package does not provide a
    default addendum view: if the host wants to add its own instructions, it
    must:

      1. Create its own Blade view (it can live at
         `resources/views/vendor/chatbot/system_prompt_addendum.blade.php`
         or wherever the host prefers).
      2. Point `chatbot.system_prompt.addendum_view` to the Blade name of
         that view (e.g. `vendor.chatbot.system_prompt_addendum` if it is
         in `resources/views/vendor/chatbot/`, or any other).

    This view is intended as a reference; hosts can copy it and
    adapt it. It receives the same variables as the base view: $user,
    $pageContext, $tools, $locale (but does NOT receive $addendum itself).

    Typical addendum use cases (ROADMAP §4 E05):
      - Host domain rules (jargon, glossary, formats).
      - Regional date format (EU dd/mm/yyyy vs US mm/dd/yyyy).
      - Product-specific ethical or tone limits.
      - Context data that changes between tenants/events.
--}}
## Domain rules

- Use the European date format (dd/mm/yyyy) when reporting dates.
- The user's role in the system is "operator". Do not suggest actions outside their permissions; if a tool is unavailable, explain it instead of pretending you executed it.
- When referring to monetary amounts, always include the currency symbol.
