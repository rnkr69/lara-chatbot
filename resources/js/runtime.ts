export type RuntimeMode = 'mpa' | 'spa';

export interface DetectOptions {
  windowRef?: Window & { Inertia?: unknown; Livewire?: unknown };
  documentRef?: Document;
}

declare global {
  interface Window {
    Inertia?: unknown;
    Livewire?: unknown;
  }
}

export function detectMode(opts: DetectOptions = {}): RuntimeMode {
  const w = opts.windowRef ?? (typeof window !== 'undefined' ? window : undefined);
  const d = opts.documentRef ?? (typeof document !== 'undefined' ? document : undefined);
  if (!w || !d) return 'mpa';

  // 1) Explicit meta override wins over heuristics — host knows better.
  const meta = d.querySelector<HTMLMetaElement>('meta[name="chatbot:mode"]');
  if (meta) {
    const v = (meta.getAttribute('content') ?? '').trim().toLowerCase();
    if (v === 'spa') return 'spa';
    if (v === 'mpa') return 'mpa';
  }

  if (w.Inertia) return 'spa';
  if (w.Livewire) return 'spa';

  return 'mpa';
}

let cached: RuntimeMode | null = null;

export function getMode(opts?: DetectOptions): RuntimeMode {
  if (cached === null) cached = detectMode(opts);
  return cached;
}

export function resetModeCache(): void {
  cached = null;
}
