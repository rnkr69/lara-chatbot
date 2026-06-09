/**
 * v2.0 / E5 — `chatbot:active-dashboard:v1` cross-tab mirror.
 *
 * Mismo patrón que `ACTIVE_CONVERSATION_KEY` (D16) pero para el slug del
 * dashboard que el usuario tenía abierto la última vez. Sirve para deep-link
 * tras reload + navegar fuera y volver. Vive en `localStorage` para que el
 * widget flotante (cuando E9 enlace al dashboard) y la página `/chatbot/dashboard`
 * acuerden el mismo slug entre pestañas.
 */

export const ACTIVE_DASHBOARD_KEY = 'chatbot:active-dashboard:v1';

function getStorage(): Storage | null {
  try {
    if (typeof window === 'undefined') return null;
    return window.localStorage;
  } catch {
    return null;
  }
}

export function loadActiveDashboard(): string | null {
  const storage = getStorage();
  if (!storage) return null;
  let raw: string | null;
  try {
    raw = storage.getItem(ACTIVE_DASHBOARD_KEY);
  } catch {
    return null;
  }
  if (raw === null || raw === '') return null;
  try {
    const parsed = JSON.parse(raw) as unknown;
    return typeof parsed === 'string' && parsed !== '' ? parsed : null;
  } catch {
    return null;
  }
}

export function saveActiveDashboard(slug: string | null): void {
  const storage = getStorage();
  if (!storage) return;
  try {
    if (slug === null || slug === '') {
      storage.removeItem(ACTIVE_DASHBOARD_KEY);
      return;
    }
    storage.setItem(ACTIVE_DASHBOARD_KEY, JSON.stringify(slug));
  } catch {
    // best-effort
  }
}
