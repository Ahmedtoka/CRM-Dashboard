// Entry for the public team test chat (/try/{token}, design 2026-09-21). It is a
// standalone Vue app on a plain Blade shell — no Inertia, no app layout, no design
// tokens — so the page stays small on a phone and looks exactly like Messenger.
import { createApp } from 'vue';
import '@fontsource/ibm-plex-sans-arabic/latin-400.css';
import '@fontsource/ibm-plex-sans-arabic/latin-600.css';
import '@fontsource/ibm-plex-sans-arabic/latin-700.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-400.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-600.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-700.css';
import '../css/try.css';
import TryApp from './try/TryApp.vue';
import type { TryState } from './try/types';

const mount = document.getElementById('try-app');

if (mount) {
    const token = mount.dataset.token ?? '';
    const initial = JSON.parse(mount.dataset.state ?? '{}') as TryState;

    createApp(TryApp, { token, initial }).mount(mount);
}
