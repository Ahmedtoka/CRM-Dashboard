import ar from './ar';
import en from './en';

export type Locale = 'ar' | 'en';
export type Messages = typeof ar;
export type TranslationParams = Record<string, string | number>;

export const LOCALES: Locale[] = ['ar', 'en'];
export const DEFAULT_LOCALE: Locale = 'ar';

const dictionaries: Record<Locale, Messages> = { ar, en };

export function normalizeLocale(value: unknown): Locale {
    return LOCALES.includes(value as Locale) ? (value as Locale) : DEFAULT_LOCALE;
}

export function directionOf(locale: Locale): 'rtl' | 'ltr' {
    return locale === 'ar' ? 'rtl' : 'ltr';
}

function lookup(dict: Messages, key: string): string | undefined {
    let node: unknown = dict;

    for (const part of key.split('.')) {
        if (node === null || typeof node !== 'object') {
            return undefined;
        }
        node = (node as Record<string, unknown>)[part];
    }

    return typeof node === 'string' ? node : undefined;
}

/**
 * Resolves a dotted key ("inbox.filters.waiting") in the locale, falling back to Arabic then the key.
 * `{name}` placeholders are replaced from params; numbers are formatted for the locale (Arabic-Indic in ar).
 */
export function translate(locale: Locale, key: string, params?: TranslationParams): string {
    const template = lookup(dictionaries[locale], key) ?? lookup(dictionaries[DEFAULT_LOCALE], key) ?? key;

    if (!params) {
        return template;
    }

    return template.replace(/\{(\w+)\}/g, (match, name: string) => {
        const value = params[name];

        if (value === undefined) {
            return match;
        }

        return typeof value === 'number' ? formatNumber(locale, value) : value;
    });
}

export function formatNumber(locale: Locale, value: number, options?: Intl.NumberFormatOptions): string {
    return new Intl.NumberFormat(locale === 'ar' ? 'ar-EG' : 'en-EG', options).format(value);
}

export function applyDocumentLocale(locale: Locale): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.lang = locale;
    document.documentElement.dir = directionOf(locale);
}
