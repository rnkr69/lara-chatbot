import { installApi, markReady } from './api.js';
import { defineWidget } from './widget.js';
import {
  installBackpackBulkSelectionSync,
  installBackpackDataTablesDecoration,
} from './backpack-datatables.js';

installApi();
defineWidget();
// v1.1.3 (#20): internalize the Backpack DataTables row-decoration hook
// so every host gets `data-chatbot-row-id` on grid rows automatically.
// Bails out silently when the `chatbot:options` meta tag is absent.
installBackpackDataTablesDecoration();
// v1.1.4 (#26): mirror Backpack bulk-action checkbox state into the page
// context so `crud.selected_ids` stays in sync with user clicks. Opt-in
// via the `chatbot:options` meta tag.
installBackpackBulkSelectionSync();
// v1.1 (findings #8): emit `chatbot:ready` once everything is wired so hosts
// loaded BEFORE the bundle (rare but possible) can subscribe to the event.
// Hosts loaded AFTER the bundle should call `window.Chatbot.whenReady(cb)`.
markReady();
