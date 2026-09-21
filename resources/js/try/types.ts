/** What `GET /try/{token}/state` returns (App\Http\Controllers\Web\TryController::state). */

export interface TryButton {
    title: string;
    payload: string;
}

export interface TryCardButton {
    type: 'web_url' | 'phone' | 'postback';
    title: string;
    url?: string;
    phone?: string;
    payload?: string;
}

export interface TryGenericCard {
    title: string;
    subtitle: string | null;
    text: string | null;
    image_url?: string | null;
    url?: string | null;
    buttons: TryCardButton[];
}

export type TryCards = { type: 'generic'; cards: TryGenericCard[] } | { type: 'button'; buttons: TryCardButton[] };

export interface TryImage {
    id: number;
    url: string;
    width: number | null;
    height: number | null;
}

export interface TryMessage {
    id: number;
    direction: 'in' | 'out';
    sender: 'customer' | 'bot' | 'user' | 'system';
    body: string | null;
    buttons: TryButton[];
    cards: TryCards | null;
    status: string | null;
    created_at: string | null;
    images: TryImage[];
}

export interface TrySession {
    token: string;
    name: string;
    run_no: number;
    ended: boolean;
    used: number;
    cap: number;
    cap_reached: boolean;
}

export interface TryState {
    open: boolean;
    reason: 'expired' | 'stopped' | 'unknown' | null;
    label: string;
    poll_ms: number;
    name_min: number;
    name_max: number;
    max_text: number;
    started: boolean;
    session: TrySession | null;
    messages: TryMessage[];
    typing: boolean;
    error?: string;
}
