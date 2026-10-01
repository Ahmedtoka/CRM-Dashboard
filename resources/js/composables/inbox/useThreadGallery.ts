import type { Attachment, Message } from '@/types/crm';
import { computed, inject, ref, type ComputedRef, type InjectionKey, type Ref, type WritableComputedRef } from 'vue';

/** One image or video in the thread gallery (spec §1.2, Task 6b). */
export interface GalleryItem {
    id: number;
    messageId: number;
    type: 'image' | 'video';
    url: string;
    thumb: string | null;
    width: number | null;
    height: number | null;
    name: string | null;
    size: number | null;
    mime: string | null;
}

export interface ThreadGallery {
    items: ComputedRef<GalleryItem[]>;
    /** Open at an attachment; `opener` gets focus back when the gallery closes. */
    openAt(attachmentId: number, opener?: HTMLElement | null): void;
    /** The open attachment's id — the source of truth (null = closed). */
    currentId: Ref<number | null>;
    /** Derived from `currentId`; setting it opens that position. */
    index: WritableComputedRef<number | null>;
    /** The element that opened the gallery, so focus can return to it. */
    opener: Ref<HTMLElement | null>;
}

export const THREAD_GALLERY: InjectionKey<ThreadGallery> = Symbol('threadGallery');

/** A stored image or video that has a URL; stickers, audio, files and pending rows are not gallery items. */
export function isGalleryAttachment(a: Attachment): a is Attachment & { type: 'image' | 'video'; url: string } {
    return (a.type === 'image' || a.type === 'video') && a.status === 'stored' && !!a.url;
}

export function toGalleryItem(a: Attachment & { type: 'image' | 'video'; url: string }, messageId: number): GalleryItem {
    return {
        id: a.id,
        messageId,
        type: a.type,
        url: a.url,
        // Videos have no thumbnail (thumb_url is null); an image's thumb_url falls back to its url.
        thumb: a.thumb_url,
        width: a.width,
        height: a.height,
        name: a.original_name,
        size: a.size_bytes,
        mime: a.mime,
    };
}

const MIME_EXT: Record<string, string> = {
    'image/jpeg': 'jpg',
    'image/png': 'png',
    'image/webp': 'webp',
    'image/gif': 'gif',
    'image/heic': 'heic',
    'video/mp4': 'mp4',
    'video/quicktime': 'mov',
    'video/webm': 'webm',
    'video/3gpp': '3gp',
};

/**
 * A safe download name: no path separators or control characters, and always an extension
 * (kept from the name, else derived from the mime type, else from the item type).
 */
export function downloadName(item: Pick<GalleryItem, 'id' | 'type' | 'name' | 'mime'>): string {
    let name = (item.name ?? '')
        .replace(/[\u0000-\u001f\u007f/\\]+/g, '_')
        .replace(/^[.\s]+|[.\s]+$/g, '')
        .trim();
    if (!name) name = `${item.type === 'video' ? 'video' : 'photo'}-${item.id}`;
    if (!/\.[A-Za-z0-9]{2,5}$/.test(name)) {
        const ext = (item.mime && MIME_EXT[item.mime.toLowerCase()]) || (item.type === 'video' ? 'mp4' : 'jpg');
        name = `${name}.${ext}`;
    }
    return name.slice(-150);
}

/** The authorised media route with `?download=1`: served as `attachment` with the real filename. */
export function downloadUrl(item: Pick<GalleryItem, 'url'>): string {
    return `${item.url}${item.url.includes('?') ? '&' : '?'}download=1`;
}

export function galleryItemsOf(attachments: Attachment[], messageId = 0): GalleryItem[] {
    return attachments.filter(isGalleryAttachment).map((a) => toGalleryItem(a, messageId));
}

/**
 * One gallery per thread: every stored image / video of the loaded messages, in thread
 * order. ChatThread creates it once and provides it; the media boxes inject it.
 */
export function useThreadGallery(messages: Ref<Message[]>): ThreadGallery {
    const items = computed<GalleryItem[]>(() => messages.value.flatMap((m) => galleryItemsOf(m.attachments ?? [], m.id)));
    const currentId = ref<number | null>(null);
    const opener = ref<HTMLElement | null>(null);
    const index = computed<number | null>({
        get: () => {
            if (currentId.value === null) return null;
            const i = items.value.findIndex((item) => item.id === currentId.value);
            return i === -1 ? null : i;
        },
        set: (i) => {
            currentId.value = i === null ? null : (items.value[i]?.id ?? null);
        },
    });

    function openAt(attachmentId: number, from: HTMLElement | null = null): void {
        if (!items.value.some((item) => item.id === attachmentId)) return;
        opener.value = from;
        currentId.value = attachmentId;
    }

    return { items, openAt, currentId, index, opener };
}

/** The thread's gallery, or null outside a ChatThread. */
export function injectThreadGallery(): ThreadGallery | null {
    return inject(THREAD_GALLERY, null);
}
