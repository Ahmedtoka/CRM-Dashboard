<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{ mode: 'today' | 'yesterday'; date: string }>();
</script>

<template>
    <Head title="Today" />
    <AppLayout />
</template>
