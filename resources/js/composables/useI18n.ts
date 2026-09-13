import { applyDocumentLocale, directionOf, normalizeLocale, translate, type Locale, type TranslationParams } from '@/i18n';
import { router } from '@inertiajs/vue3';
import { computed, readonly, ref, type ComputedRef, type Ref } from 'vue';

// One locale for the whole app, seeded from the shared `locale` prop (see app.ts).
const current = ref<Locale>('ar');

export function setCurrentLocale(value: unknown): void {
    current.value = normalizeLocale(value);
    applyDocumentLocale(current.value);
}

export interface I18n {
    t: (key: string, params?: TranslationParams) => string;
    locale: Readonly<Ref<Locale>>;
    dir: ComputedRef<'rtl' | 'ltr'>;
    setLocale: (locale: Locale) => void;
}

const dir = computed(() => directionOf(current.value));

function t(key: string, params?: TranslationParams): string {
    return translate(current.value, key, params);
}

function setLocale(locale: Locale): void {
    if (locale === current.value) {
        return;
    }

    // Flip immediately for a snappy switch; the server persists it and re-shares `locale`.
    setCurrentLocale(locale);
    router.post(`/locale/${locale}`, {}, { preserveScroll: true, preserveState: true });
}

export function useI18n(): I18n {
    return { t, locale: readonly(current), dir, setLocale };
}
