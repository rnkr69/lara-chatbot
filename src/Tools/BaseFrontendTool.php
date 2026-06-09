<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

use Rnkr69\LaraChatbot\Tools\Contracts\FrontendTool;

/**
 * Clase base para implementar `FrontendTool` (E11) con el "shim" cableado.
 *
 * Una frontend tool es una tool que el LLM "invoca" pero que el orquestador
 * NO ejecuta como acción de backend (no toca BD, no muta estado del host):
 * la ejecución real la hace el widget en el navegador. La cascada de
 * autorización (validar args + permission + tenant) se aplica igual que en
 * `BaseBackendTool` — heredamos la cascada con `extends BaseBackendTool` —
 * pero `handle()` por defecto devuelve un `ToolResult::success([])` neutro,
 * sin tocar nada del host. El orquestador (`ChatService::onToolCall`, E08):
 *
 *   1. Corre la cascada `execute()` (validate → permission → tenant → handle).
 *   2. Si OK, emite `event: frontend_action` con `{tool, args + result.data,
 *      action_id, confirmation}` para que el widget materialice el efecto.
 *   3. Mete `success(['status' => 'queued', 'action_id' => $uuid])` en el
 *      buffer que vuelve al LLM, para que el step se cierre coherentemente.
 *
 * Decisión §4/E11: `BaseFrontendTool extends BaseBackendTool` (DRY) en lugar
 * de duplicar la cascada en una clase paralela. La interfaz `FrontendTool` ya
 * extiende `BackendTool` (D8, E08) y el orquestador ya hace branching por
 * `instanceof FrontendTool` — extender `BaseBackendTool` reusa la cascada
 * existente sin acoplamientos nuevos.
 *
 * Casos de uso:
 *
 *   1. Primitivas puras de UI (NavigateTool, HighlightTool, ShowToastTool,
 *      etc.): no override `handle()`. La base devuelve `success([])` y el
 *      orquestador emite `frontend_action` con los args originales del LLM.
 *
 *   2. Primitivas con lógica backend que aporta datos al widget
 *      (DownloadFileTool firma una URL, por ejemplo): override `handle()`
 *      para devolver `success(['download_url' => $signed, 'expires_at' => $iso])`.
 *      Esos campos se MERGEAN en `frontend_action.args` antes de emitirse al
 *      widget — el LLM ve un "queued" neutro pero el widget recibe los datos
 *      necesarios para ejecutar la acción.
 *
 * Los hosts pueden extender `BaseFrontendTool` para crear sus propias FE
 * tools (ej. `OpenInvoiceModalTool`) reusando la cascada y el shim.
 */
abstract class BaseFrontendTool extends BaseBackendTool implements FrontendTool
{
    /**
     * Hook por defecto para frontend tools sin lógica de backend. Devuelve
     * un payload vacío que el orquestador interpreta como "args del LLM ya
     * son suficientes". Las subclases que necesiten enriquecer
     * `frontend_action.args` (ej. firmar una URL) override este método y
     * devuelven los campos a mergear vía `ToolResult::success([...])`.
     *
     * NO toques BD ni efectos secundarios fuera de lo estrictamente
     * necesario — el contrato de una FE tool es "valido los args y dejo
     * actuar al widget".
     *
     * @param  array<string, mixed>  $args
     */
    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        return ToolResult::success([]);
    }

    /**
     * Nombre canónico de la primitive del widget que debe despachar este
     * tool cuando se invoque. Por defecto devuelve `name()` — preserva el
     * mapeo 1-a-1 entre tool y primitive del bundle (e.g.
     * `NavigateTool::name() === 'navigate'`) que era implícito hasta 1.1.3.
     *
     * Las subclases de una FE primitive (típico: un host extiende
     * `DownloadFileTool` para validar ownership antes de firmar la URL y
     * override `name()` con un nombre propio como `download_manifest`)
     * deben override este método y devolver el nombre canónico del padre
     * (`'download_file'`). Así el LLM ve la tool con el nombre custom (con
     * su `description` propia), pero el widget sigue resolviendo al
     * primitive del bundle al recibir `frontend_action.tool`.
     *
     * El `ChatService` (E08) lee este valor en el momento de emitir el
     * evento `frontend_action`; un default desalineado provoca
     * `unknown_tool` en el widget porque no encontrará handler para el
     * `name()` custom (finding #25).
     */
    public function frontendPrimitiveName(): string
    {
        return $this->name();
    }
}
