<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const name = usePage().props.name;
</script>

<template>
    <span class="text-lg font-bold tracking-tight">{{ name }}</span>
</template>
