<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useSimulator } from '@/composables/useSimulator';
import type { SharedData } from '@/types';
import type { SimPost } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { LoaderCircle, MessagesSquare } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ posts: SimPost[] }>();

const { t } = useI18n();
const page = usePage<SharedData>();
const sim = useSimulator();

const platform = ref<PlatformValue>('facebook');
const postId = ref<string>('');
const newPostKey = ref('');
const isAd = ref(false);
const name = ref('');
const text = ref('');

const platformPosts = computed(() => props.posts.filter((p) => p.platform === platform.value));
const selectedPost = computed(() => platformPosts.value.find((p) => String(p.id) === postId.value) ?? null);

watch(platform, () => (postId.value = ''));
watch(selectedPost, (post) => {
    if (post) isAd.value = post.is_ad;
});

const postKey = computed(() => selectedPost.value?.external_id ?? newPostKey.value.trim());
const valid = computed(() => postKey.value && name.value.trim() && text.value.trim());

async function send(): Promise<void> {
    if (!valid.value) return;
    const customer = sim.newCustomer(name.value.trim());
    const result = await sim.post(
        'comment',
        '/simulator/comment',
        { platform: platform.value, post_key: postKey.value, is_ad: isAd.value, customer_key: customer.key, name: customer.name, text: text.value.trim() },
        t('simulator.comment.sent', { name: customer.name }),
        { href: `/comments?platform=${platform.value}`, label: t('simulator.open_comments') },
        ['posts'],
    );
    if (result) text.value = '';
}

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <form class="flex flex-col gap-3 rounded-lg border bg-card p-4 text-xs" @submit.prevent="send">
        <h2 class="flex items-center gap-1.5 text-sm font-medium"><MessagesSquare class="size-4" aria-hidden="true" />{{ t('simulator.comment.title') }}</h2>
        <label class="grid gap-1">
            <span class="font-medium">{{ t('simulator.platform') }}</span>
            <select v-model="platform" :class="input">
                <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
        </label>
        <label class="grid gap-1">
            <span class="font-medium">{{ t('simulator.comment.post') }}</span>
            <select v-model="postId" :class="input">
                <option value="">{{ t('simulator.comment.new_post') }}</option>
                <option v-for="p in platformPosts" :key="p.id" :value="String(p.id)">{{ (p.caption ?? p.external_id).slice(0, 60) }}</option>
            </select>
        </label>
        <label v-if="!selectedPost" class="grid gap-1">
            <span class="font-medium">{{ t('simulator.comment.post_key') }}</span>
            <input v-model="newPostKey" dir="ltr" maxlength="100" placeholder="summer-sale" :class="input" />
        </label>
        <label class="flex items-center gap-2"><input v-model="isAd" type="checkbox" class="rounded border-input" :disabled="!!selectedPost" />{{ t('simulator.comment.ad') }}</label>
        <label class="grid gap-1">
            <span class="font-medium">{{ t('simulator.message.customer_name') }}</span>
            <input v-model="name" dir="auto" maxlength="100" :class="input" />
        </label>
        <label class="grid gap-1">
            <span class="font-medium">{{ t('simulator.text') }}</span>
            <textarea v-model="text" rows="3" dir="auto" maxlength="2000" class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
        </label>
        <button type="submit" class="mt-auto inline-flex h-9 items-center justify-center gap-1.5 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="!valid || sim.busy.value !== null">
            <LoaderCircle v-if="sim.busy.value === 'comment'" class="size-4 animate-spin" aria-hidden="true" />{{ t('simulator.comment.send') }}
        </button>
    </form>
</template>
