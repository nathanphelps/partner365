<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import guestRoutes from '@/routes/guests';
import partnerRoutes from '@/routes/partners';
import type { GuestUser, Paginated } from '@/types/partner';

const props = defineProps<{
    guests: Paginated<GuestUser>;
    partnerId?: number;
    canManage: boolean;
    filters?: {
        search?: string;
        status?: string;
        account_enabled?: string;
        partner_id?: string;
        sort?: string;
        direction?: string;
    };
    partners?: { id: number; display_name: string }[];
}>();

// Selection state
const selectedIds = ref<Set<number>>(new Set());
const allSelected = computed(
    () =>
        props.guests.data.length > 0 &&
        props.guests.data.every((g) => selectedIds.value.has(g.id)),
);

function toggleAll(checked: boolean) {
    if (checked) {
        props.guests.data.forEach((g) => selectedIds.value.add(g.id));
    } else {
        selectedIds.value.clear();
    }
}

function toggleOne(id: number, checked: boolean) {
    if (checked) {
        selectedIds.value.add(id);
    } else {
        selectedIds.value.delete(id);
    }
}

// Filters
const search = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? '');
const enabledFilter = ref(props.filters?.account_enabled ?? '');
const partnerFilter = ref(props.filters?.partner_id ?? '');

let searchTimer: ReturnType<typeof setTimeout>;

function applyFilters(overrides: Record<string, string> = {}) {
    const params: Record<string, string> = {
        search: search.value,
        status: statusFilter.value,
        account_enabled: enabledFilter.value,
        ...(!props.partnerId ? { partner_id: partnerFilter.value } : {}),
        ...overrides,
    };

    Object.keys(params).forEach((k) => {
        if (!params[k]) delete params[k];
    });

    const url = props.partnerId
        ? partnerRoutes.show.url(props.partnerId)
        : guestRoutes.index.url();

    router.get(url, params, { preserveState: true, replace: true });
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 400);
}

function onFilterChange() {
    applyFilters();
}

// Actions
const actionLoading = ref(false);

function toggleEnabled(guest: GuestUser) {
    actionLoading.value = true;
    router.patch(
        guestRoutes.update.url(guest.id),
        { account_enabled: !guest.account_enabled },
        {
            preserveScroll: true,
            onFinish: () => {
                actionLoading.value = false;
            },
        },
    );
}

function resendInvite(guest: GuestUser) {
    actionLoading.value = true;
    router.post(
        guestRoutes.resend.url(guest.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                actionLoading.value = false;
            },
        },
    );
}

function deleteGuest(guest: GuestUser) {
    confirmAction.value = {
        title: 'Delete Guest User',
        description: `Are you sure you want to delete ${guest.display_name}? This cannot be undone.`,
        variant: 'destructive',
        onConfirm: () => {
            router.delete(guestRoutes.destroy.url(guest.id), {
                preserveScroll: true,
            });
            confirmAction.value = null;
        },
    };
}

// Edit modal
const editingGuest = ref<GuestUser | null>(null);
const editDisplayName = ref('');

function openEdit(guest: GuestUser) {
    editingGuest.value = guest;
    editDisplayName.value = guest.display_name;
}

function saveEdit() {
    if (!editingGuest.value) return;
    router.patch(
        guestRoutes.update.url(editingGuest.value.id),
        { display_name: editDisplayName.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingGuest.value = null;
            },
        },
    );
}

// Confirm dialog
const confirmAction = ref<{
    title: string;
    description: string;
    variant: 'default' | 'destructive';
    onConfirm: () => void;
} | null>(null);

// Bulk actions
const bulkLoading = ref(false);
const bulkResult = ref<{
    succeeded: number[];
    failed: { id: number; error: string }[];
} | null>(null);

async function executeBulkAction(
    action: 'enable' | 'disable' | 'delete' | 'resend',
) {
    const ids = Array.from(selectedIds.value);
    const labels: Record<string, string> = {
        enable: 'enable',
        disable: 'disable',
        delete: 'delete',
        resend: 'resend invitations for',
    };

    confirmAction.value = {
        title: `Bulk ${action}`,
        description: `Are you sure you want to ${labels[action]} ${ids.length} guest user(s)?`,
        variant:
            action === 'delete' || action === 'disable'
                ? 'destructive'
                : 'default',
        onConfirm: async () => {
            confirmAction.value = null;
            bulkLoading.value = true;
            try {
                const response = await fetch(guestRoutes.bulk.url(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN':
                            document.querySelector<HTMLMetaElement>(
                                'meta[name="csrf-token"]',
                            )?.content ?? '',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ action, ids }),
                });
                const data = await response.json();
                bulkResult.value = data;
                selectedIds.value.clear();
                router.reload({ preserveUrl: true });
            } finally {
                bulkLoading.value = false;
            }
        },
    };
}

// Helpers
function statusVariant(status: string): 'default' | 'destructive' | 'outline' {
    if (status === 'accepted') return 'default';
    if (status === 'failed') return 'destructive';
    return 'outline';
}

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString();
}

function daysInactiveLabel(lastSignIn: string | null): string {
    if (!lastSignIn) return 'Never';
    const days = Math.floor(
        (Date.now() - new Date(lastSignIn).getTime()) / 86_400_000,
    );
    return `${days}d`;
}

function inactiveBadgeVariant(
    lastSignIn: string | null,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (!lastSignIn) return 'destructive';
    const days = Math.floor(
        (Date.now() - new Date(lastSignIn).getTime()) / 86_400_000,
    );
    if (days >= 90) return 'destructive';
    if (days >= 60) return 'secondary';
    if (days >= 30) return 'outline';
    return 'default';
}
</script>

<template>
    <!-- Bulk result toast -->
    <div
        v-if="bulkResult"
        class="mb-4 rounded-lg border p-3 text-sm"
        :class="
            bulkResult.failed.length
                ? 'border-yellow-500 bg-yellow-50 dark:bg-yellow-950'
                : 'border-green-500 bg-green-50 dark:bg-green-950'
        "
    >
        <div class="flex items-center justify-between">
            <span>
                {{ bulkResult.succeeded.length }} succeeded<span
                    v-if="bulkResult.failed.length"
                    >, {{ bulkResult.failed.length }} failed</span
                >.
            </span>
            <Button variant="ghost" size="sm" @click="bulkResult = null"
                >Dismiss</Button
            >
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-4 flex flex-wrap gap-3">
        <Input
            v-model="search"
            placeholder="Search by name or email..."
            class="max-w-sm"
            @input="onSearchInput"
        />
        <select
            v-model="statusFilter"
            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
            @change="onFilterChange"
        >
            <option value="">All Statuses</option>
            <option value="pending_acceptance">Pending Acceptance</option>
            <option value="accepted">Accepted</option>
            <option value="failed">Failed</option>
        </select>
        <select
            v-model="enabledFilter"
            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
            @change="onFilterChange"
        >
            <option value="">All Accounts</option>
            <option value="1">Enabled</option>
            <option value="0">Disabled</option>
        </select>
        <select
            v-if="!partnerId && partners"
            v-model="partnerFilter"
            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
            @change="onFilterChange"
        >
            <option value="">All Partners</option>
            <option v-for="p in partners" :key="p.id" :value="String(p.id)">
                {{ p.display_name }}
            </option>
        </select>
    </div>

    <!-- Bulk action bar -->
    <div
        v-if="selectedIds.size > 0"
        class="mb-4 flex items-center gap-3 rounded-lg border bg-muted/50 p-3"
    >
        <span class="text-sm font-medium">{{ selectedIds.size }} selected</span>
        <Button
            size="sm"
            variant="outline"
            :disabled="bulkLoading"
            @click="executeBulkAction('enable')"
        >
            Enable
        </Button>
        <Button
            size="sm"
            variant="outline"
            :disabled="bulkLoading"
            @click="executeBulkAction('disable')"
        >
            Disable
        </Button>
        <Button
            size="sm"
            variant="outline"
            :disabled="bulkLoading"
            @click="executeBulkAction('resend')"
        >
            Resend
        </Button>
        <Button
            size="sm"
            variant="destructive"
            :disabled="bulkLoading"
            @click="executeBulkAction('delete')"
        >
            Delete
        </Button>
        <Button size="sm" variant="ghost" @click="selectedIds.clear()"
            >Clear</Button
        >
    </div>

    <!-- Table -->
    <div class="rounded-lg border bg-card">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b bg-muted/50">
                    <th v-if="canManage" class="w-10 px-4 py-3">
                        <Checkbox
                            :model-value="allSelected"
                            @update:model-value="
                                (val: boolean | 'indeterminate') =>
                                    toggleAll(val === true)
                            "
                        />
                    </th>
                    <th
                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                    >
                        Name
                    </th>
                    <th
                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                    >
                        Email
                    </th>
                    <th
                        v-if="!partnerId"
                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                    >
                        Partner Org
                    </th>
                    <th
                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                    >
                        Status
                    </th>
                    <th
                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                    >
                        Enabled
                    </th>
                    <th
                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                    >
                        <button
                            class="flex items-center gap-1 hover:text-foreground"
                            @click="
                                applyFilters({
                                    sort: 'last_sign_in_at',
                                    direction:
                                        filters?.sort === 'last_sign_in_at' &&
                                        filters?.direction !== 'desc'
                                            ? 'desc'
                                            : 'asc',
                                })
                            "
                        >
                            Last Sign In
                            <span class="text-xs">&#x21C5;</span>
                        </button>
                    </th>
                    <th
                        v-if="canManage"
                        class="px-4 py-3 text-right font-medium text-muted-foreground"
                    >
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="guest in guests.data"
                    :key="guest.id"
                    class="border-b transition-colors last:border-0 hover:bg-muted/30"
                >
                    <td v-if="canManage" class="w-10 px-4 py-3">
                        <Checkbox
                            :model-value="selectedIds.has(guest.id)"
                            @update:model-value="
                                (val: boolean | 'indeterminate') =>
                                    toggleOne(guest.id, val === true)
                            "
                        />
                    </td>
                    <td class="px-4 py-3">
                        <Link
                            :href="guestRoutes.show.url(guest.id)"
                            class="font-medium text-foreground hover:underline"
                        >
                            {{ guest.display_name }}
                        </Link>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                        {{ guest.email }}
                    </td>
                    <td v-if="!partnerId" class="px-4 py-3">
                        <Link
                            v-if="guest.partner_organization"
                            :href="
                                partnerRoutes.show.url(
                                    guest.partner_organization.id,
                                )
                            "
                            class="text-sm hover:underline"
                        >
                            {{ guest.partner_organization.display_name }}
                        </Link>
                        <span v-else class="text-muted-foreground">—</span>
                    </td>
                    <td class="px-4 py-3">
                        <Badge
                            :variant="statusVariant(guest.invitation_status)"
                        >
                            {{ statusLabel(guest.invitation_status) }}
                        </Badge>
                    </td>
                    <td class="px-4 py-3">
                        <Badge
                            :variant="
                                guest.account_enabled ? 'default' : 'outline'
                            "
                        >
                            {{ guest.account_enabled ? 'Yes' : 'No' }}
                        </Badge>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="text-muted-foreground">{{
                                formatDate(guest.last_sign_in_at)
                            }}</span>
                            <Badge
                                :variant="
                                    inactiveBadgeVariant(guest.last_sign_in_at)
                                "
                            >
                                {{ daysInactiveLabel(guest.last_sign_in_at) }}
                            </Badge>
                        </div>
                    </td>
                    <td v-if="canManage" class="px-4 py-3 text-right">
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button variant="ghost" size="sm">...</Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem @click="openEdit(guest)"
                                    >Edit</DropdownMenuItem
                                >
                                <DropdownMenuItem @click="toggleEnabled(guest)">
                                    {{
                                        guest.account_enabled
                                            ? 'Disable'
                                            : 'Enable'
                                    }}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="
                                        guest.invitation_status ===
                                        'pending_acceptance'
                                    "
                                    @click="resendInvite(guest)"
                                >
                                    Resend Invite
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    class="text-destructive"
                                    @click="deleteGuest(guest)"
                                >
                                    Delete
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </td>
                </tr>
                <tr v-if="guests.data.length === 0">
                    <td
                        :colspan="
                            canManage ? (partnerId ? 7 : 8) : partnerId ? 5 : 6
                        "
                        class="px-4 py-8 text-center text-muted-foreground"
                    >
                        No guest users found.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div
        v-if="guests.last_page > 1"
        class="mt-4 flex items-center justify-between"
    >
        <p class="text-sm text-muted-foreground">
            Showing {{ guests.data.length }} of {{ guests.total }} guests
        </p>
        <div class="flex gap-1">
            <template v-for="link in guests.links" :key="link.label">
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

    <!-- Edit modal -->
    <Dialog
        :open="!!editingGuest"
        @update:open="
            (val) => {
                if (!val) editingGuest = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Edit Guest User</DialogTitle>
                <DialogDescription
                    >Update the display name for this guest
                    user.</DialogDescription
                >
            </DialogHeader>
            <div class="py-4">
                <Input v-model="editDisplayName" placeholder="Display name" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="editingGuest = null"
                    >Cancel</Button
                >
                <Button @click="saveEdit">Save</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Confirm dialog -->
    <Dialog
        :open="!!confirmAction"
        @update:open="
            (val) => {
                if (!val) confirmAction = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ confirmAction?.title }}</DialogTitle>
                <DialogDescription>{{
                    confirmAction?.description
                }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="confirmAction = null"
                    >Cancel</Button
                >
                <Button
                    :variant="
                        confirmAction?.variant === 'destructive'
                            ? 'destructive'
                            : 'default'
                    "
                    @click="confirmAction?.onConfirm()"
                >
                    Confirm
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
