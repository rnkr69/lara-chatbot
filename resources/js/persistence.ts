export const STORAGE_KEY = 'chatbot:state:v1';

/**
 * E17 — clave secundaria en `localStorage` que comparte el `conversationId`
 * activo entre el widget flotante y la página dedicada `/chatbot`. Es el
 * canal cross-tab que cumple el DoD del ROADMAP §5/E17 ("Abrir conversación
 * X en widget, navegar a /chatbot, ver la misma conversación") incluso
 * cuando la navegación abre una pestaña nueva. Decisión D16 en §1 del
 * PROGRESS.md: NO se mezcla con `STORAGE_KEY` (que sigue per-tab en
 * sessionStorage para `isOpen`/`draft`); ambos coexisten.
 */
export const ACTIVE_CONVERSATION_KEY = 'chatbot:active-conversation:v1';

/**
 * v1.1.3 (#21) — clave `localStorage` con el id del usuario auth bajo el
 * que se persistió por última vez la conversación activa. Al boot del
 * widget se compara con el `data-user-id` actual: si difieren, se purgan
 * la conversación activa y las acciones rehydradas para evitar que el
 * usuario nuevo "herede" la sesión del anterior tras logout/login en el
 * mismo navegador.
 */
export const ACTIVE_USER_KEY = 'chatbot:active-user:v1';

export interface PersistedState {
  conversationId: string | number | null;
  isOpen: boolean;
  draft: string;
}

const DEFAULT_STATE: PersistedState = {
  conversationId: null,
  isOpen: false,
  draft: '',
};

export function defaultState(): PersistedState {
  return { ...DEFAULT_STATE };
}

function getStorage(): Storage | null {
  try {
    if (typeof window === 'undefined') return null;
    return window.sessionStorage;
  } catch {
    // Some browsers throw on `window.sessionStorage` access in private mode.
    return null;
  }
}

export function loadState(): PersistedState {
  const storage = getStorage();
  if (!storage) return defaultState();
  let raw: string | null;
  try {
    raw = storage.getItem(STORAGE_KEY);
  } catch {
    return defaultState();
  }
  if (raw === null || raw === '') return defaultState();
  try {
    const parsed = JSON.parse(raw) as Record<string, unknown>;
    if (!parsed || typeof parsed !== 'object') return defaultState();
    const conversationId =
      typeof parsed['conversationId'] === 'string' || typeof parsed['conversationId'] === 'number'
        ? (parsed['conversationId'] as string | number)
        : null;
    return {
      conversationId,
      isOpen: parsed['isOpen'] === true,
      draft: typeof parsed['draft'] === 'string' ? (parsed['draft'] as string) : '',
    };
  } catch {
    return defaultState();
  }
}

export function saveState(state: PersistedState): void {
  const storage = getStorage();
  if (!storage) return;
  try {
    storage.setItem(STORAGE_KEY, JSON.stringify(state));
  } catch {
    // Ignore quota/security errors — persistence is best-effort.
  }
}

export function clearState(): void {
  const storage = getStorage();
  if (!storage) return;
  try {
    storage.removeItem(STORAGE_KEY);
  } catch {
    // ignore
  }
}

/**
 * Cross-tab storage para el `conversationId` activo (E17 / D16).
 *
 * Usa `localStorage` (no `sessionStorage`) para que widget flotante y página
 * `/chatbot` compartan la misma conversación incluso cuando se abren en
 * pestañas distintas. El shape persistido es un escalar (string | number) en
 * formato JSON; valores no-escalares y errores de parseo se descartan.
 */
function getCrossTabStorage(): Storage | null {
  try {
    if (typeof window === 'undefined') return null;
    return window.localStorage;
  } catch {
    return null;
  }
}

export function loadActiveConversation(): string | number | null {
  const storage = getCrossTabStorage();
  if (!storage) return null;
  let raw: string | null;
  try {
    raw = storage.getItem(ACTIVE_CONVERSATION_KEY);
  } catch {
    return null;
  }
  if (raw === null || raw === '') return null;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed === 'string' || typeof parsed === 'number') {
      return parsed;
    }
    return null;
  } catch {
    return null;
  }
}

export function saveActiveConversation(id: string | number | null): void {
  const storage = getCrossTabStorage();
  if (!storage) return;
  try {
    if (id === null) {
      storage.removeItem(ACTIVE_CONVERSATION_KEY);
      return;
    }
    storage.setItem(ACTIVE_CONVERSATION_KEY, JSON.stringify(id));
  } catch {
    // best-effort
  }
}

export function clearActiveConversation(): void {
  saveActiveConversation(null);
}

/* ------------------------------------------------------------------------- *
 * v1.1.3 (#21) — Active user gating.
 * ------------------------------------------------------------------------- */

export function loadActiveUser(): string | null {
  const storage = getCrossTabStorage();
  if (!storage) return null;
  try {
    const raw = storage.getItem(ACTIVE_USER_KEY);
    return raw === null || raw === '' ? null : raw;
  } catch {
    return null;
  }
}

export function saveActiveUser(id: string | number | null | undefined): void {
  const storage = getCrossTabStorage();
  if (!storage) return;
  try {
    if (id === null || id === undefined || id === '') {
      storage.removeItem(ACTIVE_USER_KEY);
      return;
    }
    storage.setItem(ACTIVE_USER_KEY, String(id));
  } catch {
    // best-effort
  }
}

export function clearActiveUser(): void {
  saveActiveUser(null);
}

export interface DebouncedSaver {
  save(state: PersistedState): void;
  flush(): void;
  cancel(): void;
}

/**
 * Returns a debounced saver — calls within `delayMs` collapse into one write.
 * `flush()` writes immediately if there's a pending state; `cancel()` discards.
 */
export function makeDebouncedSaver(delayMs = 250): DebouncedSaver {
  let pending: PersistedState | null = null;
  let timer: ReturnType<typeof setTimeout> | null = null;

  const writeNow = (): void => {
    if (timer !== null) {
      clearTimeout(timer);
      timer = null;
    }
    if (pending !== null) {
      saveState(pending);
      pending = null;
    }
  };

  return {
    save(state: PersistedState): void {
      pending = state;
      if (timer !== null) clearTimeout(timer);
      timer = setTimeout(() => {
        timer = null;
        if (pending !== null) {
          saveState(pending);
          pending = null;
        }
      }, delayMs);
    },
    flush: writeNow,
    cancel(): void {
      if (timer !== null) {
        clearTimeout(timer);
        timer = null;
      }
      pending = null;
    },
  };
}
