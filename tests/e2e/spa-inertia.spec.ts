import { test, expect } from '@playwright/test';

/**
 * DoD ROADMAP §5/E13: "caso SPA con Inertia mock manteniendo el widget".
 * The fixture exposes a minimal `window.Inertia` with a `visit()` that
 * dispatches `inertia:navigate`. We click navigation buttons twice and
 * assert that:
 *   1. The widget element instance is the same after each navigation.
 *   2. Its open state and draft text persist across navigations.
 *   3. SPA mode was detected (no full page reload happens).
 */
test('SPA: widget stays mounted through Inertia-mock navigations', async ({ page }) => {
  await page.goto('/spa.html');
  const widget = page.locator('chatbot-widget');
  await expect(widget).toHaveAttribute('data-state', 'open');
  await expect(widget).toHaveAttribute('data-conversation-id', 'conv-spa-1');

  // Stamp a unique identity on the widget instance so we can detect re-mount.
  await widget.evaluate((el) => { (el as unknown as { __stamp?: number }).__stamp = 7777; });

  // Type a draft.
  const draft = 'survives SPA navigation';
  await widget.evaluate((el, value) => {
    const ta = (el as HTMLElement).shadowRoot!.querySelector('textarea')!;
    ta.value = value;
    ta.dispatchEvent(new Event('input'));
  }, draft);

  // Navigate via the Inertia mock — pushes state and dispatches inertia:navigate.
  await page.click('#goB');
  await page.waitForFunction(() => window.location.search.includes('p=b'));
  // The widget should be the SAME instance — SPA never unmounts it.
  let stamp = await widget.evaluate((el) => (el as unknown as { __stamp?: number }).__stamp);
  expect(stamp).toBe(7777);
  // State and draft survive.
  await expect(widget).toHaveAttribute('data-state', 'open');
  let textareaValue = await widget.evaluate(
    (el) => (el as HTMLElement).shadowRoot!.querySelector('textarea')!.value,
  );
  expect(textareaValue).toBe(draft);

  await page.click('#goC');
  await page.waitForFunction(() => window.location.search.includes('p=c'));
  stamp = await widget.evaluate((el) => (el as unknown as { __stamp?: number }).__stamp);
  expect(stamp).toBe(7777);
  textareaValue = await widget.evaluate(
    (el) => (el as HTMLElement).shadowRoot!.querySelector('textarea')!.value,
  );
  expect(textareaValue).toBe(draft);
  await expect(widget).toHaveAttribute('data-state', 'open');
});
