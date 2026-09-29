<script setup lang="ts">
// Every drawing of the room, once: the moderator, the customers, the desks, the chair and the
// robot, as in the approved simulator. Used with <use href="#br-…">. Ids are prefixed so they
// never meet another id of the app.
</script>

<template>
    <svg width="0" height="0" style="position: absolute" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient id="br-skin" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#f8dfc8" />
                <stop offset="1" stop-color="#e9bf9d" />
            </linearGradient>
            <linearGradient id="br-shade" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#fff" stop-opacity=".22" />
                <stop offset=".6" stop-color="#000" stop-opacity="0" />
                <stop offset="1" stop-color="#000" stop-opacity=".28" />
            </linearGradient>
            <linearGradient id="br-woodTop" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#b4886a" />
                <stop offset="1" stop-color="#8a634a" />
            </linearGradient>
            <linearGradient id="br-woodFront" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#5f4331" />
                <stop offset="1" stop-color="#3f2b1f" />
            </linearGradient>
            <linearGradient id="br-screen" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#1b2740" />
                <stop offset="1" stop-color="#0b1020" />
            </linearGradient>
            <linearGradient id="br-metal" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#eef1fa" />
                <stop offset="1" stop-color="#c3c9dd" />
            </linearGradient>
        </defs>

        <symbol id="br-i-facebook" viewBox="0 0 24 24">
            <path
                fill="currentColor"
                d="M13.5 22v-8h2.7l.4-3.2h-3.1V8.8c0-.9.3-1.6 1.6-1.6h1.7V4.3c-.3 0-1.3-.1-2.5-.1-2.5 0-4.1 1.5-4.1 4.2v2.4H7.4V14h2.8v8h3.3z"
            />
        </symbol>
        <symbol id="br-i-instagram" viewBox="0 0 24 24">
            <rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2.2" />
            <circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2.2" />
            <circle cx="17.3" cy="6.7" r="1.4" fill="currentColor" />
        </symbol>
        <symbol id="br-i-whatsapp" viewBox="0 0 24 24">
            <path
                fill="currentColor"
                d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1l-.8.9c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-3.3-2.9c-.2-.4.3-.4.8-1.4.1-.2 0-.3 0-.4l-.7-1.8c-.2-.5-.4-.4-.5-.4h-.5a1 1 0 0 0-.7.3 2.9 2.9 0 0 0-.9 2.2c0 1.3.9 2.5 1 2.7.1.2 1.8 2.8 4.5 3.9 1.7.7 2.3.8 3.1.6.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1l-.5-.3z"
            />
        </symbol>
        <symbol id="br-i-tiktok" viewBox="0 0 24 24">
            <path
                fill="currentColor"
                d="M16.5 3c.3 2.2 1.6 3.6 3.8 3.8v3.1c-1.4 0-2.7-.4-3.8-1.2v6.4a5.6 5.6 0 1 1-5.6-5.6c.3 0 .6 0 .9.1v3.2a2.5 2.5 0 1 0 1.6 2.3V3h3.1z"
            />
        </symbol>
        <symbol id="br-i-bot" viewBox="0 0 24 24">
            <path
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
                d="M12 2v3M5 10h14v9H5zM9 14h.01M15 14h.01M2 13v3M22 13v3"
            />
        </symbol>
        <symbol id="br-i-full" viewBox="0 0 24 24">
            <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
        </symbol>
        <symbol id="br-i-team" viewBox="0 0 24 24">
            <circle cx="9" cy="8" r="3.5" fill="none" stroke="currentColor" stroke-width="1.8" />
            <path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            <path
                d="M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.4c2.1.8 3.5 2.8 3.5 5.6"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
            />
        </symbol>

        <symbol id="br-face" viewBox="0 0 40 40">
            <ellipse cx="20" cy="20" rx="12.5" ry="14" fill="url(#br-skin)" />
            <ellipse cx="14.5" cy="21" rx="2.4" ry="1.6" fill="#f2a1a8" opacity=".45" />
            <ellipse cx="25.5" cy="21" rx="2.4" ry="1.6" fill="#f2a1a8" opacity=".45" />
            <path
                d="M12.5 14.5c2-1.6 4.5-1.6 6.3-.4M21.2 14.1c1.8-1.2 4.3-1.2 6.3.4"
                fill="none"
                stroke="#5a3a2e"
                stroke-width="1.2"
                stroke-linecap="round"
            />
            <ellipse cx="15.5" cy="18" rx="2.2" ry="2.4" fill="#fff" />
            <ellipse cx="24.5" cy="18" rx="2.2" ry="2.4" fill="#fff" />
            <circle cx="15.8" cy="18.3" r="1.5" fill="#3a2a22" />
            <circle cx="24.8" cy="18.3" r="1.5" fill="#3a2a22" />
            <circle cx="16.4" cy="17.6" r=".5" fill="#fff" />
            <circle cx="25.4" cy="17.6" r=".5" fill="#fff" />
            <path d="M19.4 21.5q.6 1.6 1.6 1.2" fill="none" stroke="#c98d78" stroke-width=".8" stroke-linecap="round" />
            <path d="M16.5 26c2.2 2 4.8 2 7 0" fill="none" stroke="#b8676a" stroke-width="1.2" stroke-linecap="round" />
        </symbol>

        <symbol id="br-g-mod" viewBox="0 0 64 80">
            <path d="M4 80c0-15 11-24 28-24s28 9 28 24z" fill="currentColor" />
            <path d="M4 80c0-15 11-24 28-24s28 9 28 24z" fill="url(#br-shade)" />
            <path d="M24 57l8 12 8-12c-2.5-1.4-13.5-1.4-16 0z" fill="#fff" opacity=".9" />
            <rect x="27" y="44" width="10" height="14" rx="4" fill="#e9bf9d" />
            <path d="M12 34C12 14 20 4 32 4s20 10 20 30v10c0 8-5 12-11 13H23c-6-1-11-5-11-13z" fill="currentColor" />
            <path d="M12 34C12 14 20 4 32 4s20 10 20 30v10c0 8-5 12-11 13H23c-6-1-11-5-11-13z" fill="url(#br-shade)" />
            <path d="M17 32c0-13 6-21 15-21s15 8 15 21v6c0 3-3 6-6 6H23c-3 0-6-3-6-6z" fill="#000" opacity=".16" />
            <g transform="translate(12 12)"><use href="#br-face" width="40" height="40" /></g>
            <path d="M14 30a18 18 0 0 1 36 0" fill="none" stroke="#1b2130" stroke-width="2.6" stroke-linecap="round" />
            <rect x="47" y="27" width="5" height="9" rx="2.5" fill="#1b2130" />
            <rect x="12" y="27" width="5" height="9" rx="2.5" fill="#1b2130" />
            <path d="M49.5 36q0 7-9 8" fill="none" stroke="#1b2130" stroke-width="1.6" />
            <circle cx="40" cy="44.5" r="2" fill="#1b2130" />
        </symbol>

        <symbol id="br-g-girl" viewBox="0 0 40 50">
            <path d="M2 50c0-9 7-15 18-15s18 6 18 15z" fill="currentColor" />
            <path d="M2 50c0-9 7-15 18-15s18 6 18 15z" fill="url(#br-shade)" />
            <rect x="16.5" y="27" width="7" height="10" rx="3" fill="#e9bf9d" />
            <path d="M7 21C7 8 12 2 20 2s13 6 13 19v7c0 5-3 8-7 8H14c-4 0-7-3-7-8z" fill="currentColor" />
            <path d="M7 21C7 8 12 2 20 2s13 6 13 19v7c0 5-3 8-7 8H14c-4 0-7-3-7-8z" fill="url(#br-shade)" />
            <path d="M10.5 20c0-9 4-14 9.5-14s9.5 5 9.5 14v4c0 2-2 4-4 4h-11c-2 0-4-2-4-4z" fill="#000" opacity=".16" />
            <g transform="translate(7 6) scale(.65)"><use href="#br-face" width="40" height="40" /></g>
        </symbol>

        <symbol id="br-g-girl2" viewBox="0 0 40 50">
            <path d="M2 50c0-9 7-15 18-15s18 6 18 15z" fill="currentColor" />
            <path d="M2 50c0-9 7-15 18-15s18 6 18 15z" fill="url(#br-shade)" />
            <rect x="16.5" y="27" width="7" height="10" rx="3" fill="#e9bf9d" />
            <path d="M8 40V18C8 8 13 3 20 3s12 5 12 15v22l-5-3v-8c0-3-1-5-3-7-2 2-6 3-8 1v14z" fill="#3b2a24" />
            <g transform="translate(7 6) scale(.65)"><use href="#br-face" width="40" height="40" /></g>
            <path d="M9 17c1-7 5-10 11-10 5 0 8 2 10 6-4-1-8-1-11 1-3 2-7 3-10 3z" fill="#4a352d" />
        </symbol>

        <symbol id="br-chair" viewBox="0 0 76 40">
            <rect x="4" y="6" width="8" height="18" rx="4" fill="#3a465e" />
            <rect x="64" y="6" width="8" height="18" rx="4" fill="#3a465e" />
            <path d="M8 40c0-10 12-16 30-16s30 6 30 16z" fill="#2c364b" />
            <rect x="34" y="36" width="8" height="4" fill="#4a5872" />
        </symbol>

        <symbol id="br-deskunit" viewBox="0 0 186 100">
            <rect x="14" y="86" width="8" height="14" fill="#2e2018" />
            <rect x="164" y="86" width="8" height="14" fill="#2e2018" />
            <rect x="4" y="60" width="178" height="28" rx="4" fill="url(#br-woodFront)" />
            <rect x="0" y="34" width="186" height="30" rx="6" fill="url(#br-woodTop)" />
            <rect x="6" y="37" width="174" height="2" rx="1" fill="#fff" opacity=".25" />
            <rect x="63" y="0" width="60" height="38" rx="5" fill="#1a2233" stroke="#4a5872" stroke-width="1.5" />
            <rect x="67" y="4" width="52" height="28" rx="2.5" fill="url(#br-screen)" />
            <rect class="glow" x="67" y="4" width="52" height="2.5" rx="1" opacity=".9" />
            <rect x="72" y="11" width="24" height="5" rx="2.5" fill="#2b3a55" />
            <rect x="92" y="18" width="22" height="5" rx="2.5" fill="#1e5a45" />
            <rect x="72" y="25" width="30" height="4" rx="2" fill="#2b3a55" />
            <rect x="89" y="38" width="8" height="8" fill="#39455c" />
            <rect x="77" y="45" width="32" height="4" rx="2" fill="#39455c" />
            <rect x="66" y="50" width="54" height="10" rx="2" fill="#2a3446" stroke="#3d4a63" stroke-width="1" />
            <path d="M70 53h46M70 56h46" stroke="#4a5872" stroke-width="1.2" stroke-dasharray="3 1.5" />
            <ellipse cx="132" cy="55" rx="4.5" ry="5.5" fill="#3d4a63" />
            <line x1="132" y1="50" x2="132" y2="53" stroke="#5c6b88" stroke-width="1" />
            <rect x="150" y="43" width="14" height="14" rx="2" fill="#e9c46a" />
            <path d="M164 47a3.5 3.5 0 0 1 0 7" fill="none" stroke="#e9c46a" stroke-width="2" />
            <path d="M155.5 49.5c1-1.5 3-1 3 .5 0-1.5 2-2 3-.5s-1 3-3 4.5c-2-1.5-4-3-3-4.5z" fill="#c2185b" />
            <rect x="20" y="42" width="15" height="13" fill="#ffe08a" transform="rotate(-6 27 48)" />
            <path d="M23 46h9M23 49h7" stroke="#b28a26" stroke-width="1" transform="rotate(-6 27 48)" />
        </symbol>

        <!-- The name plate is left blank: the leader's real name is written over it. -->
        <symbol id="br-deskleader" viewBox="0 0 264 100">
            <rect x="16" y="86" width="9" height="14" fill="#2e2018" />
            <rect x="239" y="86" width="9" height="14" fill="#2e2018" />
            <rect x="4" y="60" width="256" height="28" rx="4" fill="url(#br-woodFront)" />
            <rect x="0" y="34" width="264" height="30" rx="6" fill="#8d6a4c" />
            <rect x="0" y="34" width="264" height="30" rx="6" fill="url(#br-woodTop)" opacity=".8" />
            <rect x="6" y="37" width="252" height="2" rx="1" fill="#fff" opacity=".25" />
            <rect x="102" y="0" width="60" height="38" rx="5" fill="#1a2233" stroke="#4a5872" stroke-width="1.5" />
            <rect x="106" y="4" width="52" height="28" rx="2.5" fill="url(#br-screen)" />
            <rect class="glow" x="106" y="4" width="52" height="2.5" rx="1" opacity=".9" />
            <rect x="111" y="11" width="24" height="5" rx="2.5" fill="#2b3a55" />
            <rect x="131" y="18" width="22" height="5" rx="2.5" fill="#f5b544" opacity=".6" />
            <rect x="111" y="25" width="30" height="4" rx="2" fill="#2b3a55" />
            <rect x="128" y="38" width="8" height="8" fill="#39455c" />
            <rect x="116" y="45" width="32" height="4" rx="2" fill="#39455c" />
            <rect x="105" y="50" width="54" height="10" rx="2" fill="#2a3446" stroke="#3d4a63" stroke-width="1" />
            <path d="M109 53h46M109 56h46" stroke="#4a5872" stroke-width="1.2" stroke-dasharray="3 1.5" />
            <ellipse cx="171" cy="55" rx="4.5" ry="5.5" fill="#3d4a63" />
            <rect x="228" y="44" width="16" height="14" rx="3" fill="#b0603c" />
            <ellipse cx="236" cy="38" rx="9" ry="5" fill="#2e8b57" />
            <ellipse cx="230" cy="34" rx="6" ry="4" fill="#3aa46a" />
            <ellipse cx="242" cy="33" rx="6" ry="4" fill="#3aa46a" />
            <rect x="14" y="43" width="84" height="16" rx="2" fill="#2b2118" />
            <rect x="16" y="45" width="80" height="12" rx="1" fill="#e9c46a" />
        </symbol>

        <symbol id="br-g-robot" viewBox="0 0 52 64">
            <line x1="26" y1="2" x2="26" y2="10" stroke="#a78bfa" stroke-width="2" />
            <circle cx="26" cy="3" r="3" fill="#a78bfa" />
            <rect x="11" y="10" width="30" height="22" rx="8" fill="url(#br-metal)" stroke="#8d93b0" stroke-width="1.5" />
            <rect x="15" y="15" width="22" height="12" rx="5" fill="#141a2a" />
            <circle class="eye" cx="22" cy="21" r="2.6" />
            <circle class="eye" cx="30" cy="21" r="2.6" />
            <rect x="14" y="34" width="24" height="20" rx="6" fill="url(#br-metal)" stroke="#8d93b0" stroke-width="1.5" />
            <rect x="21" y="39" width="10" height="6" rx="2" fill="#7c5cf0" />
            <rect x="6" y="36" width="7" height="14" rx="3.5" fill="url(#br-metal)" stroke="#8d93b0" stroke-width="1.5" />
            <rect x="39" y="36" width="7" height="14" rx="3.5" fill="url(#br-metal)" stroke="#8d93b0" stroke-width="1.5" />
            <circle cx="20" cy="58" r="4.5" fill="#39415a" stroke="#8d93b0" stroke-width="1.5" />
            <circle cx="32" cy="58" r="4.5" fill="#39415a" stroke="#8d93b0" stroke-width="1.5" />
        </symbol>
    </svg>
</template>
