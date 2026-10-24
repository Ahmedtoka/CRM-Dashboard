// Round 2026-10-24 (x4): App\Confirmations\Readiness\WhatsAppReadiness::check().

export type ReadinessCheckKey = 'account' | 'phone_number_id' | 'waba_id' | 'token' | 'callback_https' | 'templates' | 'enabled' | 'delay' | 'environment';

export interface ReadinessCheck {
    key: ReadinessCheckKey;
    /** null = could not be checked (Meta unreachable). */
    ok: boolean | null;
    detail: string | null;
    /** `account` only: why the number on file is not the one the job would send from. */
    reason?: string | null;
}

export interface ReadinessTemplate {
    name: string;
    language: string;
    status: string;
    reason: string | null;
    known: boolean;
}

export interface ReadinessReport {
    ready: boolean;
    account: {
        id: number;
        name: string;
        phone: string | null;
        verified_name: string | null;
        driver: string;
        status: string;
        quality_rating: string | null;
        is_orders_number: boolean;
    } | null;
    checks: ReadinessCheck[];
    templates: ReadinessTemplate[];
    settings: {
        enabled: boolean;
        delay_minutes: number;
        template_confirm: string;
        template_reminder: string;
        template_language: string;
        skip_chat_orders: boolean;
        max_order_age_minutes: number;
    };
    environment: string;
    live_allowed_here: boolean;
}
