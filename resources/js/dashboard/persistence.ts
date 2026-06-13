/**
 * v2.0 / E5 — `chatbot:active-dashboard:v1` cross-tab mirror.
 *
 * Same pattern as `ACTIVE_CONVERSATION_KEY` (D16) but for the slug of the
 * dashboard the user had open last time. Useful for deep-linking after a
 * reload + navigating away and back. Lives in `localStorage` so the floating
 * widget (when E9 links to the dashboard) and the `/chatbot/dashboard` page
 * agree on the same slug across tabs.
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
