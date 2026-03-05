<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import MarkdownIt from 'markdown-it';
import { computed } from 'vue';
import DocSidebar from '@/components/docs/DocSidebar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

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
    sidebar: DocSection[];
    content: string;
    currentPage: string;
    pageTitle: string;
}>();

const md = new MarkdownIt({
    html: false,
    linkify: true,
    typographer: true,
});

const renderedContent = computed(() => md.render(props.content));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Documentation', href: '/docs' },
];
</script>

<template>
    <Head :title="`Docs — ${pageTitle}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-8rem)]">
            <DocSidebar :sections="sidebar" />
            <main class="flex-1 overflow-y-auto p-8">
                <article
                    class="prose prose-sm max-w-none dark:prose-invert prose-headings:text-foreground prose-p:text-foreground/90 prose-a:text-primary prose-strong:text-foreground prose-code:text-foreground prose-th:text-foreground"
                    v-html="renderedContent"
                />
            </main>
        </div>
    </AppLayout>
</template>
