<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import partners from '@/routes/partners';
import type { BreadcrumbItem } from '@/types';
import type { PartnerOrganization, Paginated } from '@/types/partner';

const props = defineProps<{
    partners: Paginated<PartnerOrganization>;
    filters: { search?: string; category?: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Partners', href: partners.index.url() },
];

const search = ref(props.filters.search ?? '');
const category = ref(props.filters.category ?? '');

let searchTimer: ReturnType<typeof setTimeout>;

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(partners.index.url(), { search: val, category: category.value }, { preserveState: true, replace: true });
    }, 400);
});

watch(category, (val) => {
    router.get(partners.index.url(), { search: search.value, category: val }, { preserveState: true, replace: true });
});

const categoryLabel: Record<string, string> = {
    vendor: 'Vendor',
    contractor: 'Contractor',
    strategic_partner: 'Strategic Partner',
    customer: 'Customer',
    other: 'Other',
};

const categoryVariant = (cat: string): 'default' | 'secondary' | 'outline' => {
    const map: Record<string, 'default' | 'secondary' | 'outline'> = {
        vendor: 'default',
        contractor: 'secondary',
        strategic_partner: 'default',
        customer: 'secondary',
        other: 'outline',
    };
    return map[cat] ?? 'outline';
};

function formatDate(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString();
}
</script>

<template>
    <Head title="Partners" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Partner Organizations</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        Manage your M365 partner tenants and their access policies.
                    </p>
                </div>
                <Link :href="partners.create.url()">
                    <Button>Add Partner</Button>
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex gap-3">
                <Input
                    v-model="search"
                    placeholder="Search by name or domain..."
                    class="max-w-sm"
                />
                <select
                    v-model="category"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
                >
                    <option value="">All Categories</option>
                    <option value="vendor">Vendor</option>
                    <option value="contractor">Contractor</option>
                    <option value="strategic_partner">Strategic Partner</option>
                    <option value="customer">Customer</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <!-- Table -->
            <div class="rounded-lg border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Domain</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Category</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">MFA Trust</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">B2B In</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">B2B Out</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Last Synced</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="partner in partners.data"
                            :key="partner.id"
                            class="border-b last:border-0 hover:bg-muted/30 transition-colors"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="partners.show.url(partner.id)"
                                    class="font-medium text-foreground hover:underline"
                                >
                                    {{ partner.display_name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ partner.domain ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="categoryVariant(partner.category)">
                                    {{ categoryLabel[partner.category] ?? partner.category }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="partner.mfa_trust_enabled ? 'default' : 'destructive'">
                                    {{ partner.mfa_trust_enabled ? 'Enabled' : 'Disabled' }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="partner.b2b_inbound_enabled ? 'text-green-600 dark:text-green-400' : 'text-muted-foreground'">
                                    {{ partner.b2b_inbound_enabled ? 'Yes' : 'No' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="partner.b2b_outbound_enabled ? 'text-green-600 dark:text-green-400' : 'text-muted-foreground'">
                                    {{ partner.b2b_outbound_enabled ? 'Yes' : 'No' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ formatDate(partner.last_synced_at) }}</td>
                        </tr>
                        <tr v-if="partners.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                                No partners found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="partners.last_page > 1" class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    Showing {{ partners.data.length }} of {{ partners.total }} partners
                </p>
                <div class="flex gap-1">
                    <template v-for="link in partners.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :class="[
                                'inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                link.active
                                    ? 'bg-primary text-primary-foreground font-medium'
                                    : 'border hover:bg-muted',
                            ]"
                            v-html="link.label"
                        />
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
