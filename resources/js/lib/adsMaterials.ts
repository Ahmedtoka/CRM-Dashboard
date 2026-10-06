import type { AdsAccess, MaterialRow } from '@/types/ads';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** What the backend allows (MaterialService::canAuthor / canOperate, MaterialController::destroy|status). */
export function useMaterialPermissions() {
    const page = usePage();
    const ads = computed(() => (page.props.ads ?? null) as AdsAccess | null);
    const user = computed(() => (page.props.auth as { user: { id: number; role: string | null } | null } | undefined)?.user ?? null);

    const isContent = computed(() => user.value?.role === 'content');
    const canManage = computed(() => ads.value?.canManage === true);
    /** Create / edit materials, manage collections, set stock availability. */
    const canAuthor = computed(() => canManage.value || isContent.value);
    /** Move a material along (activate / done) and link ads. */
    const canOperate = computed(() => canManage.value || ads.value?.isBuyer === true);

    const canDelete = (m: Pick<MaterialRow, 'creator'>) =>
        canManage.value || (isContent.value && m.creator !== null && user.value !== null && m.creator.id === user.value.id);

    /** Prepare a launch draft (content, buyers, supervisor+). */
    const canPrepare = computed(() => canAuthor.value || canOperate.value);
    /** The old direct publish dialog (admins only, O7). */
    const canDirectPublish = computed(() => ads.value?.canDirectPublish === true);

    return { canManage, canAuthor, canOperate, isContent, canDelete, canPrepare, canDirectPublish };
}

/** Link arrays are null when they were never set. */
export function links(list: string[] | null | undefined): string[] {
    return Array.isArray(list) ? list : [];
}

export const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
export const VIDEO_MIMES = ['video/mp4', 'video/quicktime', 'video/webm'];
export const ACCEPT_FILES = [...IMAGE_MIMES, ...VIDEO_MIMES].join(',');

export type FileKind = 'image' | 'video';

export function fileKind(mime: string | null | undefined): FileKind | null {
    const m = (mime ?? '').toLowerCase();
    if (IMAGE_MIMES.includes(m)) return 'image';
    if (VIDEO_MIMES.includes(m)) return 'video';

    return null;
}

export const isVideo = (mime: string | null | undefined) => (mime ?? '').toLowerCase().startsWith('video/');

/** Query object without empty values (for router.get and export links). */
export function cleanQuery(params: Record<string, string | number | null | undefined>): Record<string, string | number> {
    const out: Record<string, string | number> = {};
    for (const [k, v] of Object.entries(params)) {
        if (v !== null && v !== undefined && v !== '') out[k] = v;
    }

    return out;
}

export function queryString(params: Record<string, string | number | null | undefined>): string {
    const q = new URLSearchParams();
    for (const [k, v] of Object.entries(cleanQuery(params))) q.set(k, String(v));
    const s = q.toString();

    return s ? `?${s}` : '';
}

/** Numbered pagination: 1 … 4 5 [6] 7 8 … 20 (null = a gap). */
export function pageList(current: number, last: number): (number | null)[] {
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const out: (number | null)[] = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(last - 1, current + 1);
    if (start > 2) out.push(null);
    for (let p = start; p <= end; p++) out.push(p);
    if (end < last - 1) out.push(null);
    out.push(last);

    return out;
}
