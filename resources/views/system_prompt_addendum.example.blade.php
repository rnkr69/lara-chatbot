{{--
    EJEMPLO de vista addendum del system prompt.

    Esta NO es la vista que se publica por defecto. El paquete no provee una
    vista addendum por defecto: si el host quiere añadir instrucciones
    propias, debe:

      1. Crear su propia vista Blade (puede vivir en
         `resources/views/vendor/chatbot/system_prompt_addendum.blade.php`
         o donde el host prefiera).
      2. Apuntar `chatbot.system_prompt.addendum_view` al nombre Blade de
         esa vista (p.ej. `vendor.chatbot.system_prompt_addendum` si está
         en `resources/views/vendor/chatbot/`, o cualquier otro).

    Esta vista está pensada como referencia; los hosts pueden copiarla y
    adaptarla. Recibe las mismas variables que la vista base: $user,
    $pageContext, $tools, $locale (pero NO recibe $addendum a sí misma).

    Casos de uso típicos del addendum (ROADMAP §4 E05):
      - Reglas de dominio del host (jerga, glosario, formatos).
      - Formato de fechas regional (EU dd/mm/yyyy vs US mm/dd/yyyy).
      - Límites éticos o de tono específicos del producto.
      - Datos de contexto que cambian entre tenants/eventos.
--}}
## Domain rules

- Use the European date format (dd/mm/yyyy) when reporting dates.
- The user's role in the system is "operator". Do not suggest actions outside their permissions; if a tool is unavailable, explain it instead of pretending you executed it.
- When referring to monetary amounts, always include the currency symbol.
