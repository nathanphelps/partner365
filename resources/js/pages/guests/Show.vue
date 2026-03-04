<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { dashboard } from '@/routes';
import guests from '@/routes/guests';
import partners from '@/routes/partners';
import type { BreadcrumbItem } from '@/types';
import type { GuestUser } from '@/types/partner';

const props = defineProps<{
    guest: GuestUser;
}>();

const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Guest Users', href: guests.index.url() },
    { title: props.guest.display_name, href: guests.show.url(props.guest.id) },
];

const isAdmin = computed(() => {
    const auth = page.props.auth as { user?: { role?: string } };
    return auth?.user?.role === 'admin';
});

const statusVariant = (status: string): 'default' | 'destructive' | 'outline' => {
    if (status === 'accepted') return 'default';
    if (status === 'failed') return 'destructive';
    return 'outline';
};

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleString();
}

const showDeleteConfirm = ref(false);
const deleting = ref(false);

function deleteGuest() {
    deleting.value = true;
    router.delete(guests.destroy.url(props.guest.id), {
        onFinish: () => { deleting.value = false; },
    });
}
</script>

<template>
    <Head :title="guest.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6 max-w-3xl">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="text-2xl font-semibold">{{ guest.display_name }}</h1>
                        <Badge :variant="statusVariant(guest.invitation_status)">
                            {{ statusLabel(guest.invitation_status) }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground mt-1">{{ guest.email }}</p>
                </div>
            </div>

            <Separator />

            <!-- Guest Details -->
            <Card>
                <CardHeader>
                    <CardTitle>User Details</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3 text-sm">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                        <span class="text-muted-foreground">Display Name</span>
                        <span>{{ guest.display_name }}</span>

                        <span class="text-muted-foreground">Email</span>
                        <span>{{ guest.email }}</span>

                        <span class="text-muted-foreground">User Principal Name</span>
                        <span class="font-mono text-xs">{{ guest.user_principal_name ?? '—' }}</span>

                        <span class="text-muted-foreground">Entra User ID</span>
                        <span class="font-mono text-xs">{{ guest.entra_user_id }}</span>

                        <span class="text-muted-foreground">Invitation Status</span>
                        <span>
                            <Badge :variant="statusVariant(guest.invitation_status)">
                                {{ statusLabel(guest.invitation_status) }}
                            </Badge>
                        </span>

                        <span class="text-muted-foreground">Partner Organization</span>
                        <span>
                            <Link
                                v-if="guest.partner_organization"
                                :href="partners.show.url(guest.partner_organization.id)"
                                class="hover:underline text-foreground font-medium"
                            >
                                {{ guest.partner_organization.display_name }}
                            </Link>
                            <span v-else class="text-muted-foreground">—</span>
                        </span>

                        <span class="text-muted-foreground">Invited By</span>
                        <span>{{ guest.invited_by?.name ?? '—' }}</span>

                        <span class="text-muted-foreground">Last Sign In</span>
                        <span>{{ formatDate(guest.last_sign_in_at) }}</span>

                        <span class="text-muted-foreground">Last Synced</span>
                        <span>{{ formatDate(guest.last_synced_at) }}</span>

                        <span class="text-muted-foreground">Created</span>
                        <span>{{ formatDate(guest.created_at) }}</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Danger Zone (Admin only) -->
            <Card v-if="isAdmin" class="border-destructive/50">
                <CardHeader>
                    <CardTitle class="text-destructive">Danger Zone</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="!showDeleteConfirm">
                        <p class="text-sm text-muted-foreground mb-3">
                            Remove this guest user from the system. This does not remove them from Entra ID.
                        </p>
                        <Button variant="destructive" @click="showDeleteConfirm = true">
                            Delete Guest Record
                        </Button>
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <p class="text-sm font-medium">Are you sure? This cannot be undone.</p>
                        <div class="flex gap-2">
                            <Button variant="destructive" @click="deleteGuest" :disabled="deleting">
                                {{ deleting ? 'Deleting…' : 'Yes, Delete' }}
                            </Button>
                            <Button variant="outline" @click="showDeleteConfirm = false">Cancel</Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
