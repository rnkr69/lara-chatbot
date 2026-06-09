/**
 * E16 — REST client + banner UI for frontend tools `confirmation=confirm|manual`.
 *
 * Flow contract:
 *
 *   1. The widget receives `frontend_action` SSE frame with
 *      `confirmation !== 'auto'`. Instead of executing the primitive
 *      immediately, it queues a banner UI under the assistant message.
 *   2. The user clicks Accept / Reject in the banner.
 *
 *      For `confirmation = confirm`:
 *        - Reject  → POST {accept:false}; backend marks `rejected` (terminal).
 *        - Accept  → POST {accept:true}; backend marks `confirmed`. Widget
 *                    then runs the primitive locally and POSTs again with
 *                    {accept:true, result:{...}} so backend transitions the
 *                    row to `executed` (terminal). On primitive error, the
 *                    row stays `confirmed` — the LLM only sees `pending`/
 *                    `rejected`/`expired` in the prompt, so a stuck
 *                    `confirmed` row is silent.
 *
 *      For `confirmation = manual`:
 *        - Mark as done   → POST {accept:true,  result:{done:true}}  → executed.
 *        - Mark as not done → POST {accept:false} → rejected.
 *
 *   3. Both terminal states clear the banner and surface a small toast for
 *      affordance. The LLM picks up the outcome on the next user turn via
 *      the `## Pending actions` system-prompt section.
 *
 * The endpoint URL follows the convention
 *   `<dataEndpointDir>/actions/{action_id}/confirm`
 * which we derive from the widget's `data-endpoint` attribute (the SSE URL,
 * e.g. `/chatbot/stream`) by replacing the trailing `/stream` with
 * `/actions/{id}/confirm`. The backend mounts the route under the same
 * prefix so this stays correct for any host that customizes
 * `chatbot.route.prefix`.
 */

import { runPrimitive, type PrimitiveResult } from './actions.js';
import type { FrontendActionPayload } from './types.js';

export type PendingActionStatus = 'pending' | 'confirmed' | 'rejected' | 'executed' | 'expired';

export interface PendingActionResponse {
  id: number | string;
  action_id: string;
  status: PendingActionStatus;
  confirmation: 'confirm' | 'manual';
  tool: string;
  args: Record<string, unknown>;
  result: Record<string, unknown> | null;
  expires_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ConfirmEnvironment {
  /** Where to attach the banner — typically the assistant message node. */
  parent: HTMLElement;
  /** Append a toast under the host. */
  showToast(message: string, durationMs: number): void;
  /**
   * Run a frontend primitive locally (post-confirm execution). v1.1.3 (#16)
   * makes this return the structured `PrimitiveResult` so the banner can
   * forward errors to the backend instead of silently flipping the row to
   * `executed` on a failed primitive.
   */
  executePrimitive(payload: FrontendActionPayload): Promise<PrimitiveResult>;
  /** SSE endpoint URL (used to derive the confirm URL). */
  streamEndpoint: string;
  /** Optional bearer token forwarded by setUser(). */
  bearer: string | null;
}

export function deriveConfirmUrl(streamEndpoint: string, actionId: string): string {
  // Default convention: replace a trailing /stream with /actions/{id}/confirm.
  // Otherwise append ../actions/{id}/confirm to the parent path.
  if (streamEndpoint === '') return `/chatbot/actions/${actionId}/confirm`;
  if (streamEndpoint.endsWith('/stream')) {
    return streamEndpoint.slice(0, -'/stream'.length) + `/actions/${actionId}/confirm`;
  }
  // Trim trailing slash, then drop the last segment, then append.
  const trimmed = streamEndpoint.replace(/\/+$/, '');
  const lastSlash = trimmed.lastIndexOf('/');
  const base = lastSlash === -1 ? '' : trimmed.slice(0, lastSlash);
  return `${base}/actions/${actionId}/confirm`;
}

function readCsrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
  const token = meta?.getAttribute('content');
  return token && token !== '' ? token : null;
}

export async function postConfirm(
  url: string,
  body: { accept: boolean; result?: Record<string, unknown> },
  bearer: string | null,
): Promise<{ ok: boolean; status: number; data: PendingActionResponse | null }> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };
  const csrf = readCsrfToken();
  if (csrf !== null) headers['X-CSRF-TOKEN'] = csrf;
  if (bearer !== null) headers['Authorization'] = `Bearer ${bearer}`;

  let resp: Response;
  try {
    resp = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify(body),
    });
  } catch (err) {
    console.error('[chatbot] confirm fetch failed', err);
    return { ok: false, status: 0, data: null };
  }

  let parsed: PendingActionResponse | null = null;
  try {
    const json = await resp.json() as Record<string, unknown>;
    // The controller returns either {data: PendingActionResource} (200)
    // or {message, pending_action} (409). Normalize both shapes.
    if (json && typeof json === 'object') {
      if ('data' in json && json['data'] && typeof json['data'] === 'object') {
        parsed = json['data'] as PendingActionResponse;
      } else if ('pending_action' in json && json['pending_action'] && typeof json['pending_action'] === 'object') {
        parsed = json['pending_action'] as PendingActionResponse;
      }
    }
  } catch {
    parsed = null;
  }

  return { ok: resp.ok, status: resp.status, data: parsed };
}

/**
 * Build and attach the confirmation banner. Returns a disposer that removes
 * the banner from the DOM. Buttons fire the REST flow described in the
 * module docstring.
 */
export function attachConfirmBanner(
  payload: FrontendActionPayload,
  env: ConfirmEnvironment,
): () => void {
  const banner = document.createElement('div');
  banner.className = 'cb-confirm-banner';
  banner.dataset['actionId'] = payload.action_id;
  banner.dataset['confirmation'] = payload.confirmation;

  const title = document.createElement('div');
  title.className = 'cb-confirm-title';
  title.textContent = payload.confirmation === 'manual'
    ? `Manual action: ${payload.tool}`
    : `Confirm action: ${payload.tool}`;
  banner.appendChild(title);

  const acceptLabel = payload.confirmation === 'manual' ? 'Mark as done' : 'Accept';
  const rejectLabel = payload.confirmation === 'manual' ? 'Mark as not done' : 'Reject';

  const acceptBtn = document.createElement('button');
  acceptBtn.type = 'button';
  acceptBtn.className = 'cb-confirm-accept';
  acceptBtn.textContent = acceptLabel;

  const rejectBtn = document.createElement('button');
  rejectBtn.type = 'button';
  rejectBtn.className = 'cb-confirm-reject';
  rejectBtn.textContent = rejectLabel;

  const buttons = document.createElement('div');
  buttons.className = 'cb-confirm-buttons';
  buttons.append(acceptBtn, rejectBtn);
  banner.appendChild(buttons);

  const status = document.createElement('div');
  status.className = 'cb-confirm-status';
  status.hidden = true;
  banner.appendChild(status);

  env.parent.appendChild(banner);

  const url = deriveConfirmUrl(env.streamEndpoint, payload.action_id);
  let disposed = false;

  function setBusy(busy: boolean): void {
    acceptBtn.disabled = busy;
    rejectBtn.disabled = busy;
  }

  function showStatus(msg: string): void {
    status.hidden = false;
    status.textContent = msg;
  }

  async function onAccept(): Promise<void> {
    setBusy(true);
    if (payload.confirmation === 'manual') {
      // Manual: user marks as done. Run the host primitive (if any) before
      // the POST so the host can perform the real-world side effect (POST to
      // a backend, log, etc.) — without this, hosts that registered a
      // handler for the tool never got invoked and the row flipped to
      // `executed` with no actual action.
      //
      // v1.1.3 (#16): `executePrimitive` now returns the structured result;
      // an `ok:false` is forwarded to the backend so the LLM sees the
      // failure on its next turn (instead of "marked as done" pretending
      // nothing went wrong).
      let primitiveResult: Record<string, unknown> = { done: true };
      try {
        const outcome = await env.executePrimitive(payload);
        // Hosts on older contracts may resolve to `undefined`; only treat
        // explicit `{ok:false, ...}` as a failure that should propagate.
        if (outcome && typeof outcome === 'object' && (outcome as PrimitiveResult).ok === false) {
          primitiveResult = { done: true, ...(outcome as Record<string, unknown>) };
        }
      } catch (err) {
        primitiveResult = {
          done: true,
          ok: false,
          error: 'primitive_threw',
          message: err instanceof Error ? err.message : String(err),
        };
      }
      const resp = await postConfirm(url, { accept: true, result: primitiveResult }, env.bearer);
      if (!resp.ok) {
        showStatus(`Could not record action (HTTP ${resp.status}).`);
        setBusy(false);
        return;
      }
      env.showToast(
        primitiveResult['ok'] === false
          ? `Marked as done with error: ${payload.tool} — ${primitiveResult['message'] ?? primitiveResult['error']}`
          : `Marked as done: ${payload.tool}`,
        3000,
      );
      dispose();
      return;
    }

    // Confirm: two-step. First POST {accept:true} → confirmed.
    const confirmResp = await postConfirm(url, { accept: true }, env.bearer);
    if (!confirmResp.ok) {
      showStatus(`Could not confirm action (HTTP ${confirmResp.status}).`);
      setBusy(false);
      return;
    }

    // Now run the primitive locally.
    let primitiveResult: Record<string, unknown> = { ok: true };
    try {
      const outcome = await env.executePrimitive(payload);
      if (outcome && typeof outcome === 'object' && 'ok' in outcome) {
        primitiveResult = { ...(outcome as Record<string, unknown>) };
      }
    } catch (err) {
      primitiveResult = {
        ok: false,
        error: 'primitive_threw',
        message: err instanceof Error ? err.message : String(err),
      };
    }

    // Second POST: tells backend the row is executed (with the failure
    // payload when applicable — the LLM will read it in `## Pending actions`).
    const executedResp = await postConfirm(url, { accept: true, result: primitiveResult }, env.bearer);
    if (!executedResp.ok) {
      showStatus(`Action executed but result not recorded (HTTP ${executedResp.status}).`);
      setBusy(false);
      return;
    }

    if (primitiveResult['ok'] === false) {
      env.showToast(
        `Action ${payload.tool} failed: ${primitiveResult['message'] ?? primitiveResult['error']}`,
        4000,
      );
    } else {
      env.showToast(`Done: ${payload.tool}`, 3000);
    }
    dispose();
  }

  async function onReject(): Promise<void> {
    setBusy(true);
    const resp = await postConfirm(url, { accept: false }, env.bearer);
    if (!resp.ok) {
      showStatus(`Could not reject action (HTTP ${resp.status}).`);
      setBusy(false);
      return;
    }
    env.showToast(`Rejected: ${payload.tool}`, 3000);
    dispose();
  }

  function dispose(): void {
    if (disposed) return;
    disposed = true;
    banner.remove();
  }

  acceptBtn.addEventListener('click', () => { void onAccept(); });
  rejectBtn.addEventListener('click', () => { void onReject(); });

  return dispose;
}

/**
 * Run the primitive bundled with `actions.ts`. Pulled out into a thin shim so
 * `confirm.ts` doesn't need to know which primitive matches which tool — the
 * existing cascade (host-tool > primitive) inside `runPrimitive` keeps that
 * logic in one place.
 */
export function runPrimitiveAfterConfirm(
  payload: FrontendActionPayload,
  hostElement: HTMLElement,
  showToast: (msg: string, duration: number) => void,
): Promise<PrimitiveResult> {
  return Promise.resolve(runPrimitive(payload, { hostElement, showToast }));
}
