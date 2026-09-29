<script setup lang="ts">
import BoardEntryPanel from '@/components/board/BoardEntryPanel.vue';
import BoardMemberPanel from '@/components/board/BoardMemberPanel.vue';
import BoardRosterPanel from '@/components/board/BoardRosterPanel.vue';
import type { BoardSelection } from '@/types/board';

// What was picked in the room: a customer, a desk, or the roster.
defineProps<{ selection: BoardSelection; canManage: boolean }>();
defineEmits<{ select: [selection: BoardSelection] }>();
</script>

<template>
    <BoardEntryPanel
        v-if="selection?.kind === 'entry'"
        :entry-id="selection.id"
        :can-manage="canManage"
        @close="$emit('select', null)"
        @member="$emit('select', { kind: 'member', id: $event })"
    />
    <BoardMemberPanel
        v-else-if="selection?.kind === 'member'"
        :member-id="selection.id"
        :can-manage="canManage"
        @close="$emit('select', null)"
        @entry="$emit('select', { kind: 'entry', id: $event })"
    />
    <BoardRosterPanel
        v-else-if="selection?.kind === 'roster'"
        :can-manage="canManage"
        @close="$emit('select', null)"
        @member="$emit('select', { kind: 'member', id: $event })"
    />
</template>
