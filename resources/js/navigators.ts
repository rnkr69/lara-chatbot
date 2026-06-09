export interface NavigateOptions {
  replace?: boolean;
}

export type NavigatorFn = (url: string, options?: NavigateOptions) => void;

interface InertiaShape {
  visit?: (url: string, options?: Record<string, unknown>) => void;
}

interface LivewireShape {
  navigate?: (url: string, options?: Record<string, unknown>) => void;
}

export const mpaNavigator: NavigatorFn = (url, options) => {
  if (typeof window === 'undefined') return;
  if (options?.replace === true) window.location.replace(url);
  else window.location.assign(url);
};

export const inertiaNavigator: NavigatorFn = (url, options) => {
  const w = window as Window & { Inertia?: InertiaShape };
  if (w.Inertia?.visit) {
    w.Inertia.visit(url, options ? { ...options } : {});
    return;
  }
  mpaNavigator(url, options);
};

export const livewireNavigator: NavigatorFn = (url, options) => {
  const w = window as Window & { Livewire?: LivewireShape };
  if (w.Livewire?.navigate) {
    w.Livewire.navigate(url, options ? { ...options } : {});
    return;
  }
  mpaNavigator(url, options);
};

/**
 * Picks an SPA-aware default navigator based on what the host exposes at call time.
 * Falls back to `mpaNavigator` when neither Inertia nor Livewire is present.
 * Re-evaluates on each call so SPA frameworks that hydrate after the widget
 * boots are still detected.
 */
export function selectDefaultNavigator(): NavigatorFn {
  const w = window as Window & { Inertia?: InertiaShape; Livewire?: LivewireShape };
  if (w.Inertia?.visit) return inertiaNavigator;
  if (w.Livewire?.navigate) return livewireNavigator;
  return mpaNavigator;
}
