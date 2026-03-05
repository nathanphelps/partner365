<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import templateRoutes from '@/routes/templates';
import type { BreadcrumbItem } from '@/types';

type Template = {
    id: number;
    name: string;
    description: string;
    policy_config: Record<string, boolean>;
    created_at: string;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

defineProps<{
    templates: Paginated<Template>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Templates', href: templateRoutes.index.url() },
];

function formatDate(val: string): string {
    return new Date(val).toLocaleDateString();
}

function enabledPoliciesCount(config: Record<string, boolean>): number {
    return Object.values(config).filter(Boolean).length;
}
</script>

<template>
    <Head title="Partner Templates" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Partner Templates</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Reusable policy configurations for partner
                        organizations.
                    </p>
                </div>
                <Link :href="templateRoutes.create.url()">
                    <Button>Create Template</Button>
                </Link>
            </div>

            <!-- Table -->
            <div class="rounded-lg border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Name
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Description
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Policies Enabled
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Created
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="template in templates.data"
                            :key="template.id"
                            class="border-b transition-colors last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="templateRoutes.edit.url(template.id)"
                                    class="font-medium text-foreground hover:underline"
                                >
                                    {{ template.name }}
                                </Link>
                            </td>
                            <td
                                class="max-w-xs px-4 py-3 text-muted-foreground"
                            >
                                {{ template.description || '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{
                                    enabledPoliciesCount(template.policy_config)
                                }}
                                /
                                {{ Object.keys(template.policy_config).length }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatDate(template.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="templateRoutes.edit.url(template.id)"
                                >
                                    <Button variant="outline" size="sm"
                                        >Edit</Button
                                    >
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="templates.data.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No templates created yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="templates.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    Showing {{ templates.data.length }} of
                    {{ templates.total }} templates
                </p>
                <div class="flex gap-1">
                    <template v-for="link in templates.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :class="[
                                'inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                link.active
                                    ? 'bg-primary font-medium text-primary-foreground'
                                    : 'border hover:bg-muted',
                            ]"
                            ><!-- eslint-disable-next-line vue/no-v-html --><span
                                v-html="link.label"
                        /></Link>
                        <span
                            v-else
                            class="inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm text-muted-foreground opacity-50"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
