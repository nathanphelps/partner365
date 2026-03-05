<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CircleHelp } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
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

const props = defineProps<{
    templates: {
        id: number;
        name: string;
        policy_config: Record<string, boolean>;
    }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Partners', href: partners.index.url() },
    { title: 'Add Partner', href: partners.create.url() },
];

// Wizard step
const step = ref(1);

// Step 1: Tenant resolution
const tenantIdInput = ref('');
const resolving = ref(false);
const resolveError = ref('');
const resolvedOrg = ref<{
    display_name: string;
    domain: string;
    tenant_id: string;
} | null>(null);

async function resolveTenant() {
    if (!tenantIdInput.value.trim()) return;
    resolving.value = true;
    resolveError.value = '';
    resolvedOrg.value = null;

    try {
        const resp = await fetch(partners.resolveTenant.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    (
                        document.querySelector(
                            'meta[name="csrf-token"]',
                        ) as HTMLMetaElement
                    )?.content ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify({ tenant_id: tenantIdInput.value.trim() }),
        });

        if (!resp.ok) {
            const data = await resp.json();
            resolveError.value = data.message ?? 'Failed to resolve tenant.';
            return;
        }

        resolvedOrg.value = await resp.json();
    } catch {
        resolveError.value = 'Network error. Please try again.';
    } finally {
        resolving.value = false;
    }
}

function proceedToStep2() {
    if (!resolvedOrg.value) return;
    step.value = 2;
}

// Step 2: Template + policies
const selectedTemplateId = ref<number | ''>('');

const defaultPolicies: Record<string, boolean> = {
    mfa_trust_enabled: false,
    device_trust_enabled: false,
    direct_connect_enabled: false,
    b2b_inbound_enabled: true,
    b2b_outbound_enabled: true,
};

const policyConfig = ref<Record<string, boolean>>({ ...defaultPolicies });

const policyLabels: Record<string, string> = Object.fromEntries(
    policyDefinitions.map((p) => [p.key, p.label]),
);

watch(selectedTemplateId, (id) => {
    if (!id) {
        policyConfig.value = { ...defaultPolicies };
        return;
    }
    const tpl = props.templates.find((t) => t.id === id);
    if (tpl) {
        policyConfig.value = { ...defaultPolicies, ...tpl.policy_config };
    }
});

// Step 3: Review + submit
const form = useForm({
    tenant_id: '',
    display_name: '',
    domain: '',
    category: 'other' as string,
    template_id: null as number | null,
    policy_config: {} as Record<string, boolean>,
});

function proceedToStep3() {
    step.value = 3;
    form.tenant_id = resolvedOrg.value?.tenant_id ?? tenantIdInput.value;
    form.display_name = resolvedOrg.value?.display_name ?? '';
    form.domain = resolvedOrg.value?.domain ?? '';
    form.template_id = selectedTemplateId.value || null;
    form.policy_config = { ...policyConfig.value };
}

function submit() {
    form.post(partners.store.url());
}
</script>

<template>
    <Head title="Add Partner" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-2xl flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-semibold">Add Partner Organization</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Connect a new M365 partner tenant.
                </p>
            </div>

            <!-- Step indicator -->
            <div class="flex items-center gap-2 text-sm">
                <span
                    :class="
                        step >= 1
                            ? 'font-semibold text-foreground'
                            : 'text-muted-foreground'
                    "
                >
                    1. Resolve Tenant
                </span>
                <span class="text-muted-foreground">/</span>
                <span
                    :class="
                        step >= 2
                            ? 'font-semibold text-foreground'
                            : 'text-muted-foreground'
                    "
                >
                    2. Policies
                </span>
                <span class="text-muted-foreground">/</span>
                <span
                    :class="
                        step >= 3
                            ? 'font-semibold text-foreground'
                            : 'text-muted-foreground'
                    "
                >
                    3. Review
                </span>
            </div>

            <Separator />

            <!-- Step 1: Resolve Tenant -->
            <Card v-if="step === 1">
                <CardHeader>
                    <CardTitle>Resolve Tenant</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <Label for="tenant-id"
                            >Tenant ID (UUID or domain)</Label
                        >
                        <div class="flex gap-2">
                            <Input
                                id="tenant-id"
                                v-model="tenantIdInput"
                                placeholder="e.g. contoso.com or 00000000-0000-0000-0000-000000000000"
                                class="flex-1"
                                @keydown.enter="resolveTenant"
                            />
                            <Button
                                @click="resolveTenant"
                                :disabled="resolving || !tenantIdInput.trim()"
                            >
                                {{ resolving ? 'Resolving…' : 'Resolve' }}
                            </Button>
                        </div>
                        <p v-if="resolveError" class="text-sm text-destructive">
                            {{ resolveError }}
                        </p>
                    </div>

                    <!-- Resolved org preview -->
                    <div
                        v-if="resolvedOrg"
                        class="flex flex-col gap-1 rounded-lg border bg-muted/30 p-4"
                    >
                        <p class="text-sm font-medium">
                            {{ resolvedOrg.display_name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ resolvedOrg.domain }}
                        </p>
                        <p class="font-mono text-xs text-muted-foreground">
                            {{ resolvedOrg.tenant_id }}
                        </p>
                    </div>

                    <div class="flex gap-2 pt-2">
                        <Button @click="proceedToStep2" :disabled="!resolvedOrg"
                            >Next: Set Policies</Button
                        >
                        <Link :href="partners.index.url()">
                            <Button variant="outline">Cancel</Button>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Step 2: Policies -->
            <Card v-if="step === 2">
                <CardHeader>
                    <CardTitle>Configure Policies</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <!-- Category -->
                    <div class="flex flex-col gap-1.5">
                        <Label for="category">Category</Label>
                        <select
                            id="category"
                            v-model="form.category"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                        >
                            <option value="vendor">Vendor</option>
                            <option value="contractor">Contractor</option>
                            <option value="strategic_partner">
                                Strategic Partner
                            </option>
                            <option value="customer">Customer</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <!-- Template selector -->
                    <div
                        v-if="templates.length > 0"
                        class="flex flex-col gap-1.5"
                    >
                        <Label for="template">Apply Template (optional)</Label>
                        <select
                            id="template"
                            v-model="selectedTemplateId"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                        >
                            <option :value="''">
                                No template — configure manually
                            </option>
                            <option
                                v-for="tpl in templates"
                                :key="tpl.id"
                                :value="tpl.id"
                            >
                                {{ tpl.name }}
                            </option>
                        </select>
                    </div>

                    <Separator />

                    <!-- Policy toggles -->
                    <div class="flex flex-col gap-3">
                        <p class="text-sm font-medium">Policy Settings</p>
                        <TooltipProvider>
                            <div
                                v-for="policy in policyDefinitions"
                                :key="policy.key"
                                class="flex items-center justify-between py-1.5"
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
                                    <p
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ policy.description }}
                                    </p>
                                </div>
                                <Checkbox
                                    :id="`p-${policy.key}`"
                                    :checked="policyConfig[policy.key]"
                                    @update:checked="
                                        (v: boolean) => {
                                            policyConfig[policy.key] = v;
                                        }
                                    "
                                />
                            </div>
                        </TooltipProvider>
                    </div>

                    <div class="flex gap-2 pt-2">
                        <Button @click="proceedToStep3">Next: Review</Button>
                        <Button variant="outline" @click="step = 1"
                            >Back</Button
                        >
                    </div>
                </CardContent>
            </Card>

            <!-- Step 3: Review + Submit -->
            <Card v-if="step === 3">
                <CardHeader>
                    <CardTitle>Review &amp; Confirm</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div
                        class="flex flex-col gap-2 rounded-lg border bg-muted/30 p-4 text-sm"
                    >
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Name</span>
                            <span class="font-medium">{{
                                form.display_name
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Domain</span>
                            <span>{{ form.domain || '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Tenant ID</span>
                            <span class="font-mono text-xs">{{
                                form.tenant_id
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Category</span>
                            <span>{{ form.category }}</span>
                        </div>
                        <Separator class="my-1" />
                        <p class="font-medium">Policies</p>
                        <div
                            v-for="(val, key) in form.policy_config"
                            :key="key"
                            class="flex justify-between"
                        >
                            <span class="text-muted-foreground">{{
                                policyLabels[key] ?? key
                            }}</span>
                            <span
                                :class="
                                    val
                                        ? 'text-green-600 dark:text-green-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ val ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                    </div>

                    <div
                        v-if="form.errors && Object.keys(form.errors).length"
                        class="text-sm text-destructive"
                    >
                        <p v-for="(msg, field) in form.errors" :key="field">
                            {{ msg }}
                        </p>
                    </div>

                    <div class="flex gap-2">
                        <Button @click="submit" :disabled="form.processing">
                            {{
                                form.processing ? 'Creating…' : 'Create Partner'
                            }}
                        </Button>
                        <Button variant="outline" @click="step = 2"
                            >Back</Button
                        >
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
