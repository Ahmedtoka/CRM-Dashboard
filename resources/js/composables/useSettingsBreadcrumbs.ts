import { useI18n } from '@/composables/useI18n';
import { hubLocate, hubNav, settingsCrumbs } from '@/lib/settingsHub';
import type { BreadcrumbItem, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/** AppLayout breadcrumbs for a settings page: «الإعدادات › المجموعة › الصفحة», the group found from the URL. */
export function useSettingsBreadcrumbs(title: () => string): ComputedRef<BreadcrumbItem[]> {
    const { t } = useI18n();
    const page = usePage<SharedData>();

    return computed(() => {
        const groups = hubNav(page.props.auth.user?.role as never, Boolean((page.props as { devTools?: boolean }).devTools));

        return settingsCrumbs(t, hubLocate(groups, page.url)?.group.id ?? null, title()).map((c) => ({ title: c.title, href: c.href ?? '' }));
    });
}
