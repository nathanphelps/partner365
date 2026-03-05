<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { CircleHelp } from 'lucide-vue-next';
import { ref, reactive, computed } from 'vue';
import GuestUserTable from '@/components/GuestUserTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import { policyDefinitions } from '@/lib/policy-config';
import { dashboard } from '@/routes';
import partners from '@/routes/partners';
import type { BreadcrumbItem } from '@/types';
import type { PartnerOrganization, GuestUser, Paginated } from '@/types/partner';

const props = defineProps<{
    partner: PartnerOrganization;
    guests: Paginated<GuestUser>;
    canManage: boolean;
}>();

const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Partners', href: partners.index.url() },
    {
        title: props.partner.display_name,
        href: partners.show.url(props.partner.id),
    },
];

const categoryLabel: Record<string, string> = {
    vendor: 'Vendor',
    contractor: 'Contractor',
    strategic_partner: 'Strategic Partner',
    customer: 'Customer',
    other: 'Other',
};

// Policy form state
const policyForm = reactive({
    mfa_trust_enabled: props.partner.mfa_trust_enabled,
    device_trust_enabled: props.partner.device_trust_enabled,
    direct_connect_inbound_enabled: props.partner.direct_connect_inbound_enabled,
    direct_connect_outbound_enabled: props.partner.direct_connect_outbound_enabled,
    b2b_inbound_enabled: props.partner.b2b_inbound_enabled,
    b2b_outbound_enabled: props.partner.b2b_outbound_enabled,
});

const savingPolicies = ref(false);
const policiesSaved = ref(false);

function savePolicies() {
    savingPolicies.value = true;
    router.patch(partners.update.url(props.partner.id), policyForm, {
        preserveScroll: true,
        onSuccess: () => {
            policiesSaved.value = true;
            setTimeout(() => {
                policiesSaved.value = false;
            }, 3000);
        },
        onFinish: () => {
            savingPolicies.value = false;
        },
    });
}

// Notes form state
const notes = ref(props.partner.notes ?? '');
const savingNotes = ref(false);
const notesSaved = ref(false);

function saveNotes() {
    savingNotes.value = true;
    router.patch(
        partners.update.url(props.partner.id),
        { notes: notes.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                notesSaved.value = true;
                setTimeout(() => {
                    notesSaved.value = false;
                }, 3000);
            },
            onFinish: () => {
                savingNotes.value = false;
            },
        },
    );
}

// Delete
const showDeleteConfirm = ref(false);
const deleting = ref(false);

function deletePartner() {
    deleting.value = true;
    router.delete(partners.destroy.url(props.partner.id), {
        onFinish: () => {
            deleting.value = false;
        },
    });
}

function formatDate(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleString();
}


const isAdmin = computed(() => {
    const auth = page.props.auth as { user?: { role?: string } };
    return auth?.user?.role === 'admin';
});

const directConnectStatus = computed(() => {
    const inbound = props.partner.direct_connect_inbound_enabled;
    const outbound = props.partner.direct_connect_outbound_enabled;

    if (inbound && outbound) {
        return {
            label: 'Active',
            variant: 'default' as const,
            description:
                "Both inbound and outbound direct connect are enabled. The partner must also enable direct connect on their side for Teams shared channels to work.",
        };
    }
    if (inbound || outbound) {
        return {
            label: 'Partial',
            variant: 'secondary' as const,
            description: `Only ${inbound ? 'inbound' : 'outbound'} direct connect is enabled. Enable both directions and ensure the partner has also enabled direct connect for full functionality.`,
        };
    }
    return {
        label: 'Disabled',
        variant: 'outline' as const,
        description:
            'Direct connect is disabled. Enable inbound and outbound toggles in Access Policies above to allow Teams shared channels with this partner.',
    };
});
</script>

<template>
    <Head :title="partner.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-semibold">
                            {{ partner.display_name }}
                        </h1>
                        <Badge variant="secondary">{{
                            categoryLabel[partner.category] ?? partner.category
                        }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ partner.domain ?? 'No domain' }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Tenant ID: {{ partner.tenant_id }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <span class="text-sm text-muted-foreground"
                        >Last synced:
                        {{ formatDate(partner.last_synced_at) }}</span
                    >
                </div>
            </div>

            <Separator />

            <!-- Policy Toggles + Notes -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Access Policies</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <TooltipProvider>
                        <div
                            v-for="policy in policyDefinitions"
                            :key="policy.key"
                            class="flex items-center justify-between py-2"
                        >
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <p class="text-sm font-medium">
                                        {{ policy.label }}
                                    </p>
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <CircleHelp
                                                class="size-3.5 text-muted-foreground"
                                            />
                                        </TooltipTrigger>
                                        <TooltipContent
                                            class="max-w-xs"
                                            side="right"
                                        >
                                            {{ policy.tooltip }}
                                        </TooltipContent>
                                    </Tooltip>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{ policy.description }}
                                </p>
                            </div>
                            <Switch
                                :id="`policy-${policy.key}`"
                                :model-value="(policyForm as any)[policy.key]"
                                @update:model-value="
                                    (val: boolean) => {
                                        (policyForm as any)[policy.key] = val;
                                    }
                                "
                            />
                        </div>
                    </TooltipProvider>

                    <div class="flex items-center gap-3 pt-2">
                        <Button
                            @click="savePolicies"
                            :disabled="savingPolicies"
                        >
                            {{ savingPolicies ? 'Saving…' : 'Save Policies' }}
                        </Button>
                        <span
                            v-if="policiesSaved"
                            class="text-sm text-green-600 dark:text-green-400"
                            >Saved.</span
                        >
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Notes</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <Textarea
                        v-model="notes"
                        placeholder="Add notes about this partner organization..."
                        class="min-h-[120px]"
                    />
                    <div class="flex items-center gap-3">
                        <Button
                            @click="saveNotes"
                            :disabled="savingNotes"
                            variant="secondary"
                        >
                            {{ savingNotes ? 'Saving…' : 'Save Notes' }}
                        </Button>
                        <span
                            v-if="notesSaved"
                            class="text-sm text-green-600 dark:text-green-400"
                            >Saved.</span
                        >
                    </div>
                </CardContent>
            </Card>
            </div>

            <!-- Guest Users -->
            <Card>
                <CardHeader>
                    <CardTitle>Guest Users ({{ guests.total }})</CardTitle>
                </CardHeader>
                <CardContent>
                    <GuestUserTable
                        :guests="guests"
                        :partner-id="partner.id"
                        :can-manage="canManage"
                    />
                </CardContent>
            </Card>

            <!-- Danger Zone (Admin only) -->
            <Card v-if="isAdmin" class="border-destructive/50">
                <CardHeader>
                    <CardTitle class="text-destructive">Danger Zone</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="!showDeleteConfirm">
                        <p class="mb-3 text-sm text-muted-foreground">
                            Permanently delete this partner organization and all
                            associated data.
                        </p>
                        <Button
                            variant="destructive"
                            @click="showDeleteConfirm = true"
                        >
                            Delete Partner
                        </Button>
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <p class="text-sm font-medium">
                            Are you sure? This cannot be undone.
                        </p>
                        <div class="flex gap-2">
                            <Button
                                variant="destructive"
                                @click="deletePartner"
                                :disabled="deleting"
                            >
                                {{ deleting ? 'Deleting…' : 'Yes, Delete' }}
                            </Button>
                            <Button
                                variant="outline"
                                @click="showDeleteConfirm = false"
                                >Cancel</Button
                            >
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
