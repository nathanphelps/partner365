<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';

const props = defineProps<{
    name: string;
    faviconPath: string | null;
    size?: 'sm' | 'lg';
}>();

const sizeClass = props.size === 'lg' ? 'size-10' : 'size-6';
const textClass = props.size === 'lg' ? 'text-sm' : 'text-xs';
</script>

<template>
    <Avatar :class="[sizeClass, 'shrink-0 overflow-hidden rounded']">
        <AvatarImage
            v-if="faviconPath"
            :src="`/storage/${faviconPath}`"
            :alt="name"
        />
        <AvatarFallback :class="['rounded text-foreground', textClass]">
            {{ getInitials(name) }}
        </AvatarFallback>
    </Avatar>
</template>
