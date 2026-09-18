// Entry for the public legal pages (/privacy, /terms, /data-deletion). They are plain
// server-rendered Blade (crawlable by Meta's review tooling), so this only loads the
// app's fonts and design tokens — no Vue, no Inertia.
import '@fontsource/ibm-plex-sans-arabic/latin-400.css';
import '@fontsource/ibm-plex-sans-arabic/latin-600.css';
import '@fontsource/ibm-plex-sans-arabic/latin-700.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-400.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-600.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-700.css';
import '../css/app.css';
