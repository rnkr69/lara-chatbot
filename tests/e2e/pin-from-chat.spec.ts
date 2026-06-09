import { test, expect } from '@playwright/test';

/**
 * v2.0 / E6 — pin from chat E2E.
 *
 * The fixture (`fixtures/pin-from-chat.html`) mounts the floating widget
 * and stubs `/chatbot/stream` to emit a single pinnable `table` block,
 * plus the dashboards CRUD JSON endpoints. The spec drives the UI:
 *   - sends a message → the stub returns the pinnable block,
 *   - hovers + clicks 📌,
 *   - drives the modal,
 *   - asserts on the captured POST shape via `window.__pinFixture`.
 *
 * The widget uses an OPEN shadow root, so Playwright's CSS locators
 * pierce it for descendants (the `.cb-pin-button`, `.cb-pin-modal-*`
 * selectors are queried as if on the light DOM).
 */

interface PinFixture {
  rows: Array<{ id: number; slug: string; name: string; is_default: boolean }>;
  forceWidgetsStatus: number;
  forceWidgetsBody: unknown;
  widgetsPosts: Array<{ url: string; body: Record<string, unknown> }>;
  createPosts: Array<Record<string, unknown>>;
  listDashboardsCalls: number;
}

declare global {
  interface Window { __pinFixture: PinFixture }
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
  });
});

async function sendMessageAndWaitForPinnableBlock(page: import('@playwright/test').Page): Promise<void> {
  // The widget mounts with data-default-open="true"; type a message,
  // press Enter — the stubbed SSE returns the pinnable block.
  await page.locator('chatbot-widget').locator('.composer textarea').fill('hi');
  await page.locator('chatbot-widget').locator('.composer textarea').press('Enter');
  await expect(page.locator('chatbot-widget').locator('.cb-pin-wrapper')).toBeVisible();
}

test('mounts the 📌 button on pinnable blocks (and only on them)', async ({ page }) => {
  await page.goto('/pin-from-chat.html');
  await sendMessageAndWaitForPinnableBlock(page);

  // Wrapper is present; button exists in the DOM (CSS hides it until
  // hover/focus, but it's there).
  const wrapper = page.locator('chatbot-widget').locator('.cb-pin-wrapper');
  await expect(wrapper).toHaveCount(1);
  const btn = wrapper.locator('.cb-pin-button');
  await expect(btn).toHaveCount(1);
  await expect(btn).toHaveAttribute('aria-label', 'Pin to dashboard');
});

test('click 📌 → modal lists dashboards → submit POSTs to /widgets with the right shape', async ({ page }) => {
  await page.goto('/pin-from-chat.html');
  await sendMessageAndWaitForPinnableBlock(page);

  // Hover surfaces the button (opacity 0 → 1); click it.
  await page.locator('chatbot-widget').locator('.cb-pin-wrapper').hover();
  await page.locator('chatbot-widget').locator('.cb-pin-button').click();

  // Modal appears in the shadow root.
  await expect(page.locator('chatbot-widget').locator('.cb-pin-modal')).toBeVisible();

  // Default-first selection lands on Mi Panel (is_default: true).
  const select = page.locator('chatbot-widget').locator('.cb-pin-modal-select');
  await expect(select).toHaveValue('mi-panel');

  // Submit.
  await page.locator('chatbot-widget').locator('.cb-pin-modal-submit').click();

  // POST captured with the full pin payload.
  await page.waitForFunction(() => window.__pinFixture.widgetsPosts.length === 1);
  const posts = await page.evaluate(() => window.__pinFixture.widgetsPosts);
  expect(posts).toHaveLength(1);
  expect(posts[0]?.url).toContain('/chatbot/dashboards/mi-panel/widgets');
  const body = posts[0]?.body as Record<string, unknown>;
  expect(body['block_type']).toBe('table');
  expect(body['block_id']).toBe('blk-test-1');
  expect(body['source']).toMatchObject({ tool: 'list_users', args: { limit: 10 } });
  expect(body['snapshot']).toMatchObject({ data: { caption: 'Users' } });

  // Modal closed; success toast appeared with View dashboard link.
  await expect(page.locator('chatbot-widget').locator('.cb-pin-modal-overlay')).toHaveCount(0);
  const toast = page.locator('chatbot-widget').locator('.toast');
  await expect(toast).toBeVisible();
  await expect(toast).toContainText('Mi Panel');
  await expect(toast.locator('a')).toHaveAttribute('target', '_blank');
});

test('inline "create" path: createDashboard then pinWidget to the new slug', async ({ page }) => {
  await page.goto('/pin-from-chat.html');
  await sendMessageAndWaitForPinnableBlock(page);

  await page.locator('chatbot-widget').locator('.cb-pin-wrapper').hover();
  await page.locator('chatbot-widget').locator('.cb-pin-button').click();
  await expect(page.locator('chatbot-widget').locator('.cb-pin-modal')).toBeVisible();

  // Switch to "create" mode by clicking the second radio (create).
  const createRadio = page.locator('chatbot-widget').locator('.cb-pin-modal-mode input[type="radio"]').nth(1);
  await createRadio.check();
  await page.locator('chatbot-widget').locator('.cb-pin-modal-create-input').fill('Brand New Panel');
  await page.locator('chatbot-widget').locator('.cb-pin-modal-submit').click();

  await page.waitForFunction(() => window.__pinFixture.widgetsPosts.length === 1);
  const created = await page.evaluate(() => window.__pinFixture.createPosts);
  expect(created).toEqual([{ name: 'Brand New Panel' }]);
  const posts = await page.evaluate(() => window.__pinFixture.widgetsPosts);
  expect(posts[0]?.url).toContain('/chatbot/dashboards/brand-new-panel/widgets');
});

test('422 dashboard_full keeps modal open and shows inline error', async ({ page }) => {
  await page.goto('/pin-from-chat.html');
  await sendMessageAndWaitForPinnableBlock(page);

  await page.evaluate(() => {
    window.__pinFixture.forceWidgetsStatus = 422;
    window.__pinFixture.forceWidgetsBody = {
      message: 'Maximum reached',
      errors: { dashboard: ['Maximum of 50 widgets reached.'] },
    };
  });

  await page.locator('chatbot-widget').locator('.cb-pin-wrapper').hover();
  await page.locator('chatbot-widget').locator('.cb-pin-button').click();
  await expect(page.locator('chatbot-widget').locator('.cb-pin-modal')).toBeVisible();
  await page.locator('chatbot-widget').locator('.cb-pin-modal-submit').click();

  // Inline error appears and modal stays mounted.
  const errEl = page.locator('chatbot-widget').locator('.cb-pin-modal-error');
  await expect(errEl).toBeVisible();
  await expect(errEl).toContainText(/full/i);
  await expect(page.locator('chatbot-widget').locator('.cb-pin-modal')).toBeVisible();
  // No toast (no success).
  await expect(page.locator('chatbot-widget').locator('.toast')).toHaveCount(0);
});

test('ESC and dim-click both close the modal', async ({ page }) => {
  await page.goto('/pin-from-chat.html');
  await sendMessageAndWaitForPinnableBlock(page);

  // Open via 📌, close via ESC.
  await page.locator('chatbot-widget').locator('.cb-pin-wrapper').hover();
  await page.locator('chatbot-widget').locator('.cb-pin-button').click();
  await expect(page.locator('chatbot-widget').locator('.cb-pin-modal')).toBeVisible();
  await page.locator('chatbot-widget').locator('.cb-pin-modal').press('Escape');
  await expect(page.locator('chatbot-widget').locator('.cb-pin-modal-overlay')).toHaveCount(0);

  // Open again, close via click on the dim overlay.
  await page.locator('chatbot-widget').locator('.cb-pin-wrapper').hover();
  await page.locator('chatbot-widget').locator('.cb-pin-button').click();
  await expect(page.locator('chatbot-widget').locator('.cb-pin-modal')).toBeVisible();
  // Click on the overlay outside the dialog. The modal max-width is 320px;
  // its .cb-pin-modal sits centered inside the overlay. Click the top-left
  // corner of the overlay (outside the dialog).
  const overlayBox = await page.locator('chatbot-widget').locator('.cb-pin-modal-overlay').boundingBox();
  if (!overlayBox) throw new Error('overlay not measurable');
  await page.mouse.click(overlayBox.x + 5, overlayBox.y + 5);
  await expect(page.locator('chatbot-widget').locator('.cb-pin-modal-overlay')).toHaveCount(0);
});
