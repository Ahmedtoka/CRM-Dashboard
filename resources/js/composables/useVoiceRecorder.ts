import { onScopeDispose, ref } from 'vue';

function extensionFor(mime: string): string {
    if (mime.includes('ogg')) return 'ogg';
    if (mime.includes('mp4') || mime.includes('m4a')) return 'm4a';
    return 'webm';
}

/**
 * Records a voice note (mic permission → MediaRecorder → stopped File). Prefers
 * `audio/ogg;codecs=opus` so WhatsApp sends don't need a server-side transcode;
 * falls back to `audio/webm;codecs=opus` (Task 2's WhatsAppAdapter transcodes that).
 *
 * `onAutoStop` fires when `maxSeconds` is reached without an explicit `stop()` call,
 * so the caller can drop the file into its tray exactly like a manual stop.
 */
export function useVoiceRecorder(maxSeconds = 300, onAutoStop?: (file: File) => void) {
    const state = ref<'idle' | 'recording' | 'denied' | 'unsupported'>('idle');
    const elapsed = ref(0);
    const starting = ref(false);
    let recorder: MediaRecorder | null = null;
    let stream: MediaStream | null = null;
    let chunks: Blob[] = [];
    let timer: number | undefined;
    let onStop: ((file: File | null) => void) | null = null;
    let discard = false;
    let disposed = false;
    let cancelledDuringStart = false;

    const pickMime = () => ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus'].find((m) => MediaRecorder.isTypeSupported(m)) ?? '';

    function teardown(): void {
        window.clearInterval(timer);
        timer = undefined;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        recorder = null;
        elapsed.value = 0;
        if (state.value === 'recording') state.value = 'idle';
    }

    async function start(): Promise<void> {
        // Guards a double-click / double-tap: ignore while a permission prompt is
        // pending or a recording is already live.
        if (starting.value || state.value === 'recording') return;

        if (typeof MediaRecorder === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
            state.value = 'unsupported';
            return;
        }

        starting.value = true;
        cancelledDuringStart = false;
        let localStream: MediaStream;
        try {
            localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            starting.value = false;
            state.value = 'denied';
            return;
        }
        starting.value = false;

        // The component was unmounted or the recording cancelled while the
        // permission prompt was pending: never start, just release the mic.
        if (disposed || cancelledDuringStart) {
            localStream.getTracks().forEach((track) => track.stop());
            return;
        }

        stream = localStream;
        const mime = pickMime();
        recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
        chunks = [];
        discard = false;
        recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
        recorder.onstop = () => {
            const type = recorder?.mimeType || mime || 'audio/webm';
            const file = discard || !chunks.length ? null : new File(chunks, `voice-${Date.now()}.${extensionFor(type)}`, { type: type.split(';')[0] });
            teardown();
            onStop?.(file);
            onStop = null;
        };
        recorder.start(250);
        state.value = 'recording';
        timer = window.setInterval(() => {
            elapsed.value += 1;
            if (elapsed.value >= maxSeconds) {
                window.clearInterval(timer);
                // Auto-stop: nobody is awaiting `stop()` here, so deliver the file
                // through the callback instead of letting it vanish.
                void stop().then((file) => file && onAutoStop?.(file));
            }
        }, 1000);
    }

    function stop(): Promise<File | null> {
        return new Promise((resolve) => {
            if (!recorder) return resolve(null);
            onStop = resolve;
            recorder.stop();
        });
    }

    function cancel(): void {
        if (starting.value) {
            // Permission prompt still pending: mark it so `start()` releases the
            // mic the moment the promise settles instead of beginning to record.
            cancelledDuringStart = true;
            return;
        }
        discard = true;
        recorder?.stop();
    }

    onScopeDispose(() => {
        disposed = true;
        cancel();
    });

    return { state, elapsed, starting, start, stop, cancel };
}
