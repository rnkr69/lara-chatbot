import { test, expect } from '@playwright/test';

/**
 * DoD ROADMAP §5/E17: "Abrir conversación X en el widget, navegar a /chatbot:
 * se ve la misma conversación".
 *
 * Flow:
 *   1. Open `/widget-floating.html` — the widget mounts floating mode with
 *      `data-conversation-id="cross-tab-77"`, which the rehydrate path mirrors
 *      to `localStorage["chatbot:active-conversation:v1"]` (D16).
 *   2. Navigate (click) to `/page.html` — `<chatbot-widget mode="page">` boots.
 *   3. The page-mode widget reads the cross-tab key, sets its active id, and
 *      the sidebar (loaded from the mocked GET /chatbot/conversations) marks
 *      the matching row as `cb-sidebar-item-active`.
 */
test('handoff: widget mode → page mode shares the conversation_id via localStorage', async ({ page }) => {
  // ── Step 1: floating widget seeds the cross-tab key.
  await page.goto('/widget-floating.html');
  const floating = page.locator('chatbot-widget');
  await expect(floating).toHaveAttribute('data-conversation-id', 'cross-tab-77');

  const seeded = await page.evaluate(() => window.localStorage.getItem('chatbot:active-conversation:v1'));
  expect(seeded).toBe('"cross-tab-77"');

  // ── Step 2: navigate to /page.html.
  await page.click('#goPage');
  await page.waitForURL('**/page.html');

  // ── Step 3: page-mode widget rehydrates from localStorage and the sidebar
  //           highlights the matching row.
  const widget = page.locator('chatbot-widget');
  await expect(widget).toHaveAttribute('data-mode', 'page');
  await expect(widget).toHaveAttribute('data-conversation-id', 'cross-tab-77');

  // The sidebar lives in the shadow DOM; reach in to find the active item.
  const activeTitle = await widget.evaluate((el) => {
    const root = (el as HTMLElement).shadowRoot!;
    const item = root.querySelector('.cb-sidebar-item.cb-sidebar-item-active');
    return item?.querySelector('.cb-sidebar-item-title')?.textContent ?? null;
  });
  expect(activeTitle).toBe('Handed-off thread');
});

test('page mode: clicking a sidebar row sets data-conversation-id and writes localStorage', async ({ page }) => {
  // Cleanly start without any prior cross-tab state.
  await page.addInitScript(() => {
    window.localStorage.removeItem('chatbot:active-conversation:v1');
    window.sessionStorage.removeItem('chatbot:state:v1');
  });
  await page.goto('/page.html');

  const widget = page.locator('chatbot-widget');
  await expect(widget).toHaveAttribute('data-mode', 'page');

  // Click the second row in the sidebar.
  await widget.evaluate((el) => {
    const root = (el as HTMLElement).shadowRoot!;
    const items = root.querySelectorAll('.cb-sidebar-item-button');
    (items[1] as HTMLButtonElement).click();
  });

  await expect(widget).toHaveAttribute('data-conversation-id', 'other-1');
  const stored = await page.evaluate(() => window.localStorage.getItem('chatbot:active-conversation:v1'));
  expect(stored).toBe('"other-1"');
});
