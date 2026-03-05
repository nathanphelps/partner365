<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type DocPage = {
    title: string;
    slug: string;
    active: boolean;
};

type DocSection = {
    name: string;
    admin: boolean;
    pages: DocPage[];
};

const props = defineProps<{
    sections: DocSection[];
}>();

const page = usePage();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');

const visibleSections = computed(() =>
    props.sections.filter((s) => !s.admin || isAdmin.value),
);
</script>

<template>
    <nav class="w-64 shrink-0 border-r border-border">
        <div class="sticky top-0 h-full overflow-y-auto p-4">
            <div
                v-for="section in visibleSections"
                :key="section.name"
                class="mb-6"
            >
                <h3
                    class="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    {{ section.name }}
                </h3>
                <ul class="space-y-1">
                    <li v-for="docPage in section.pages" :key="docPage.slug">
                        <Link
                            :href="`/docs/${docPage.slug}`"
                            class="block rounded-md px-3 py-1.5 text-sm transition-colors"
                            :class="
                                docPage.active
                                    ? 'bg-accent font-medium text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
                            "
                        >
                            {{ docPage.title }}
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</template>
