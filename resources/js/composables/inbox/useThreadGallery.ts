import type { Attachment, Message } from '@/types/crm';
import { computed, inject, ref, type ComputedRef, type InjectionKey, type Ref } from 'vue';

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
}

export interface ThreadGallery {
    items: ComputedRef<GalleryItem[]>;
    /** Open at an attachment; `opener` gets focus back when the gallery closes. */
    openAt(attachmentId: number, opener?: HTMLElement | null): void;
    index: Ref<number | null>;
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
    };
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
    const index = ref<number | null>(null);
    const opener = ref<HTMLElement | null>(null);

    function openAt(attachmentId: number, from: HTMLElement | null = null): void {
        const i = items.value.findIndex((item) => item.id === attachmentId);
        if (i === -1) return;
        opener.value = from;
        index.value = i;
    }

    return { items, openAt, index, opener };
}

/** The thread's gallery, or null outside a ChatThread. */
export function injectThreadGallery(): ThreadGallery | null {
    return inject(THREAD_GALLERY, null);
}
