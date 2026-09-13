import { useApi } from '@/composables/useApi';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export interface BroadcastingConfig {
    key: string;
    host: string | null;
    port: number | string | null;
    scheme: string | null;
}

/** Pusher channel authorization response from /broadcasting/auth. */
interface ChannelAuthData {
    auth: string;
    channel_data?: string;
    shared_secret?: string;
}

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

let instance: Echo<'reverb'> | null = null;
let attempted = false;

function envConfig(): BroadcastingConfig | null {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (typeof key !== 'string' || key === '') {
        return null;
    }

    return {
        key,
        host: (import.meta.env.VITE_REVERB_HOST as string | undefined) ?? null,
        port: (import.meta.env.VITE_REVERB_PORT as string | undefined) ?? null,
        scheme: (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? null,
    };
}

/**
 * Lazily creates the Echo (Reverb) singleton from the shared `broadcasting` prop, falling back to VITE_REVERB_*.
 * Returns null when broadcasting is not configured; callers then rely on polling.
 */
export function getEcho(shared?: BroadcastingConfig | null): Echo<'reverb'> | null {
    if (instance || attempted) {
        return instance;
    }

    attempted = true;
    const config = shared?.key ? shared : envConfig();

    if (!config || typeof window === 'undefined') {
        return null;
    }

    const scheme = config.scheme ?? 'http';
    const port = Number(config.port ?? (scheme === 'https' ? 443 : 80));
    const api = useApi();

    try {
        window.Pusher = Pusher;
        instance = new Echo({
            broadcaster: 'reverb',
            key: config.key,
            wsHost: config.host ?? window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            // Auth through axios so the rotating XSRF cookie is always used.
            authorizer: (channel: { name: string }) => ({
                authorize: (socketId: string, callback: (error: Error | null, data: ChannelAuthData | null) => void) => {
                    api.post<ChannelAuthData>('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
                        .then((response) => callback(null, response.data))
                        .catch((error: Error) => callback(error, null));
                },
            }),
        });

        api.interceptors.request.use((request) => {
            const socketId = instance?.socketId();

            if (socketId) {
                request.headers.set('X-Socket-ID', socketId);
            }

            return request;
        });
    } catch (error) {
        console.warn('[echo] broadcasting unavailable, falling back to polling', error);
        instance = null;
    }

    return instance;
}
