import { test, expect } from '@playwright/test';

/**
 * DoD ROADMAP §5/E13: "MPA con 3 page loads consecutivos manteniendo
 * conversación". The fixture sets data-conversation-id="conv-42" and
 * data-default-open="true" on first load; we verify the widget rehydrates
 * the conversation id, the open state, and a draft after each reload.
 */
test('MPA: conversation, open state and draft survive 3 page loads', async ({ page }) => {
  // Page load 1
  await page.goto('/mpa.html');
  const widget = page.locator('chatbot-widget');
  await expect(widget).toHaveAttribute('data-conversation-id', 'conv-42');
  await expect(widget).toHaveAttribute('data-state', 'open');

  // Type a draft into the textarea (inside the shadow root).
  const draftFirst = 'half-typed message survives reload';
  await widget.evaluate((el, draft) => {
    const ta = (el as HTMLElement).shadowRoot!.querySelector('textarea')!;
    ta.value = draft;
    ta.dispatchEvent(new Event('input'));
  }, draftFirst);

  // Wait for the debounced saver to flush (250ms + buffer).
  await page.waitForTimeout(400);

  // Page load 2: state should rehydrate from sessionStorage.
  await page.reload();
  await expect(widget).toHaveAttribute('data-conversation-id', 'conv-42');
  await expect(widget).toHaveAttribute('data-state', 'open');
  const draftAfterReload2 = await widget.evaluate((el) => {
    return (el as HTMLElement).shadowRoot!.querySelector('textarea')!.value;
  });
  expect(draftAfterReload2).toBe(draftFirst);

  // Mutate the draft and reload again.
  const draftSecond = 'edited again before third load';
  await widget.evaluate((el, draft) => {
    const ta = (el as HTMLElement).shadowRoot!.querySelector('textarea')!;
    ta.value = draft;
    ta.dispatchEvent(new Event('input'));
  }, draftSecond);
  await page.waitForTimeout(400);

  // Page load 3: still rehydrating.
  await page.reload();
  await expect(widget).toHaveAttribute('data-conversation-id', 'conv-42');
  await expect(widget).toHaveAttribute('data-state', 'open');
  const draftAfterReload3 = await widget.evaluate((el) => {
    return (el as HTMLElement).shadowRoot!.querySelector('textarea')!.value;
  });
  expect(draftAfterReload3).toBe(draftSecond);

  // Confirm the storage key is the canonical chatbot:state:v1.
  const stored = await page.evaluate(() => window.sessionStorage.getItem('chatbot:state:v1'));
  expect(stored).not.toBeNull();
  expect(stored).toContain('"conversationId":"conv-42"');
});
