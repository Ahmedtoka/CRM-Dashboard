// Generates public/sounds/notify.wav — a short two-tone 8-bit PCM beep for the
// notification bell/sound preference (Dashboard Experience Task 14). No
// dependencies; run once with `node scripts/make-notify-sound.mjs` from
// backend/ and commit the resulting file.
import { writeFileSync, mkdirSync } from 'node:fs';

const rate = 22050;
const tones = [[880, 0.09], [1320, 0.09]];
const samples = [];
for (const [freq, secs] of tones) {
    const n = Math.floor(rate * secs);
    for (let i = 0; i < n; i++) {
        const envelope = Math.min(1, i / 200, (n - i) / 400);
        samples.push(128 + Math.round(90 * envelope * Math.sin((2 * Math.PI * freq * i) / rate)));
    }
}
const data = Buffer.from(samples);
const header = Buffer.alloc(44);
header.write('RIFF', 0); header.writeUInt32LE(36 + data.length, 4); header.write('WAVE', 8);
header.write('fmt ', 12); header.writeUInt32LE(16, 16); header.writeUInt16LE(1, 20); header.writeUInt16LE(1, 22);
header.writeUInt32LE(rate, 24); header.writeUInt32LE(rate, 28); header.writeUInt16LE(1, 32); header.writeUInt16LE(8, 34);
header.write('data', 36); header.writeUInt32LE(data.length, 40);
mkdirSync(new URL('../public/sounds/', import.meta.url), { recursive: true });
writeFileSync(new URL('../public/sounds/notify.wav', import.meta.url), Buffer.concat([header, data]));
console.log('public/sounds/notify.wav', 44 + data.length, 'bytes');
