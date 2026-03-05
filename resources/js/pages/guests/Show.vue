<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import guests from '@/routes/guests';
import partners from '@/routes/partners';
import type { BreadcrumbItem } from '@/types';
import type { GuestGroup, GuestApp, GuestTeam, GuestSite } from '@/types/guest';
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

const statusVariant = (
    status: string,
): 'default' | 'destructive' | 'outline' => {
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
        onFinish: () => {
            deleting.value = false;
        },
    });
}

// Access tab state
const groupsData = ref<GuestGroup[]>([]);
const groupsLoading = ref(false);
const groupsError = ref(false);
const groupsFetched = ref(false);

const appsData = ref<GuestApp[]>([]);
const appsLoading = ref(false);
const appsError = ref(false);
const appsFetched = ref(false);

const teamsData = ref<GuestTeam[]>([]);
const teamsLoading = ref(false);
const teamsError = ref(false);
const teamsFetched = ref(false);

const sitesData = ref<GuestSite[]>([]);
const sitesLoading = ref(false);
const sitesError = ref(false);
const sitesFetched = ref(false);

async function fetchAccessData(tab: string | number) {
    const tabStr = String(tab);
    if (tabStr === 'groups' && !groupsFetched.value) {
        groupsLoading.value = true;
        groupsError.value = false;
        try {
            const { data } = await axios.get(
                `/guests/${props.guest.id}/groups`,
            );
            groupsData.value = data;
            groupsFetched.value = true;
        } catch {
            groupsError.value = true;
        } finally {
            groupsLoading.value = false;
        }
    } else if (tabStr === 'apps' && !appsFetched.value) {
        appsLoading.value = true;
        appsError.value = false;
        try {
            const { data } = await axios.get(`/guests/${props.guest.id}/apps`);
            appsData.value = data;
            appsFetched.value = true;
        } catch {
            appsError.value = true;
        } finally {
            appsLoading.value = false;
        }
    } else if (tabStr === 'teams' && !teamsFetched.value) {
        teamsLoading.value = true;
        teamsError.value = false;
        try {
            const { data } = await axios.get(`/guests/${props.guest.id}/teams`);
            teamsData.value = data;
            teamsFetched.value = true;
        } catch {
            teamsError.value = true;
        } finally {
            teamsLoading.value = false;
        }
    } else if (tabStr === 'sites' && !sitesFetched.value) {
        sitesLoading.value = true;
        sitesError.value = false;
        try {
            const { data } = await axios.get(`/guests/${props.guest.id}/sites`);
            sitesData.value = data;
            sitesFetched.value = true;
        } catch {
            sitesError.value = true;
        } finally {
            sitesLoading.value = false;
        }
    }
}

function retryTab(tab: string) {
    if (tab === 'groups') groupsFetched.value = false;
    if (tab === 'apps') appsFetched.value = false;
    if (tab === 'teams') teamsFetched.value = false;
    if (tab === 'sites') sitesFetched.value = false;
    fetchAccessData(tab);
}

function groupTypeLabel(type: string): string {
    const labels: Record<string, string> = {
        security: 'Security',
        microsoft365: 'Microsoft 365',
        distribution: 'Distribution',
    };
    return labels[type] ?? type;
}
</script>

<template>
    <Head :title="guest.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-semibold">
                            {{ guest.display_name }}
                        </h1>
                        <Badge
                            :variant="statusVariant(guest.invitation_status)"
                        >
                            {{ statusLabel(guest.invitation_status) }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ guest.email }}
                    </p>
                </div>
            </div>

            <Separator />

            <Tabs
                default-value="overview"
                @update:model-value="fetchAccessData"
            >
                <TabsList>
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="groups">Groups</TabsTrigger>
                    <TabsTrigger value="apps">Apps</TabsTrigger>
                    <TabsTrigger value="teams">Teams</TabsTrigger>
                    <TabsTrigger value="sites">Sites</TabsTrigger>
                </TabsList>

                <!-- Overview Tab -->
                <TabsContent value="overview">
                    <Card>
                        <CardHeader>
                            <CardTitle>User Details</CardTitle>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-3 text-sm">
                            <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                                <span class="text-muted-foreground"
                                    >Display Name</span
                                >
                                <span>{{ guest.display_name }}</span>

                                <span class="text-muted-foreground">Email</span>
                                <span>{{ guest.email }}</span>

                                <span class="text-muted-foreground"
                                    >User Principal Name</span
                                >
                                <span class="font-mono text-xs">{{
                                    guest.user_principal_name ?? '—'
                                }}</span>

                                <span class="text-muted-foreground"
                                    >Entra User ID</span
                                >
                                <span class="font-mono text-xs">{{
                                    guest.entra_user_id
                                }}</span>

                                <span class="text-muted-foreground"
                                    >Invitation Status</span
                                >
                                <span>
                                    <Badge
                                        :variant="
                                            statusVariant(
                                                guest.invitation_status,
                                            )
                                        "
                                    >
                                        {{
                                            statusLabel(guest.invitation_status)
                                        }}
                                    </Badge>
                                </span>

                                <span class="text-muted-foreground"
                                    >Partner Organization</span
                                >
                                <span>
                                    <Link
                                        v-if="guest.partner_organization"
                                        :href="
                                            partners.show.url(
                                                guest.partner_organization.id,
                                            )
                                        "
                                        class="font-medium text-foreground hover:underline"
                                    >
                                        {{
                                            guest.partner_organization
                                                .display_name
                                        }}
                                    </Link>
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </span>

                                <span class="text-muted-foreground"
                                    >Invited By</span
                                >
                                <span>{{ guest.invited_by?.name ?? '—' }}</span>

                                <span class="text-muted-foreground"
                                    >Last Sign In</span
                                >
                                <span>{{
                                    formatDate(guest.last_sign_in_at)
                                }}</span>

                                <span class="text-muted-foreground"
                                    >Last Synced</span
                                >
                                <span>{{
                                    formatDate(guest.last_synced_at)
                                }}</span>

                                <span class="text-muted-foreground"
                                    >Created</span
                                >
                                <span>{{ formatDate(guest.created_at) }}</span>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Danger Zone (Admin only) -->
                    <Card v-if="isAdmin" class="mt-6 border-destructive/50">
                        <CardHeader>
                            <CardTitle class="text-destructive"
                                >Danger Zone</CardTitle
                            >
                        </CardHeader>
                        <CardContent>
                            <div v-if="!showDeleteConfirm">
                                <p class="mb-3 text-sm text-muted-foreground">
                                    Remove this guest user from the system. This
                                    does not remove them from Entra ID.
                                </p>
                                <Button
                                    variant="destructive"
                                    @click="showDeleteConfirm = true"
                                >
                                    Delete Guest Record
                                </Button>
                            </div>
                            <div v-else class="flex flex-col gap-3">
                                <p class="text-sm font-medium">
                                    Are you sure? This cannot be undone.
                                </p>
                                <div class="flex gap-2">
                                    <Button
                                        variant="destructive"
                                        @click="deleteGuest"
                                        :disabled="deleting"
                                    >
                                        {{
                                            deleting
                                                ? 'Deleting…'
                                                : 'Yes, Delete'
                                        }}
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
                </TabsContent>

                <!-- Groups Tab -->
                <TabsContent value="groups">
                    <Card>
                        <CardHeader>
                            <CardTitle>Group Memberships</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                v-if="groupsLoading"
                                class="flex items-center justify-center py-8"
                            >
                                <div
                                    class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"
                                />
                                <span class="ml-2 text-sm text-muted-foreground"
                                    >Loading groups…</span
                                >
                            </div>
                            <div
                                v-else-if="groupsError"
                                class="py-8 text-center"
                            >
                                <p class="text-sm text-muted-foreground">
                                    Unable to load groups. Microsoft Graph API
                                    may be unavailable.
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="mt-3"
                                    @click="retryTab('groups')"
                                    >Retry</Button
                                >
                            </div>
                            <div
                                v-else-if="
                                    groupsData.length === 0 && groupsFetched
                                "
                                class="py-8 text-center"
                            >
                                <p class="text-sm text-muted-foreground">
                                    No groups found for this guest user.
                                </p>
                            </div>
                            <table
                                v-else-if="groupsData.length > 0"
                                class="w-full text-sm"
                            >
                                <thead>
                                    <tr
                                        class="border-b text-left text-muted-foreground"
                                    >
                                        <th class="pb-2 font-medium">
                                            Display Name
                                        </th>
                                        <th class="pb-2 font-medium">Type</th>
                                        <th class="pb-2 font-medium">
                                            Description
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="group in groupsData"
                                        :key="group.id"
                                        class="border-b last:border-0"
                                    >
                                        <td class="py-2">
                                            {{ group.displayName }}
                                        </td>
                                        <td class="py-2">
                                            <Badge variant="outline">{{
                                                groupTypeLabel(group.groupType)
                                            }}</Badge>
                                        </td>
                                        <td class="py-2 text-muted-foreground">
                                            {{ group.description ?? '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Apps Tab -->
                <TabsContent value="apps">
                    <Card>
                        <CardHeader>
                            <CardTitle>App Assignments</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                v-if="appsLoading"
                                class="flex items-center justify-center py-8"
                            >
                                <div
                                    class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"
                                />
                                <span class="ml-2 text-sm text-muted-foreground"
                                    >Loading apps…</span
                                >
                            </div>
                            <div v-else-if="appsError" class="py-8 text-center">
                                <p class="text-sm text-muted-foreground">
                                    Unable to load apps. Microsoft Graph API may
                                    be unavailable.
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="mt-3"
                                    @click="retryTab('apps')"
                                    >Retry</Button
                                >
                            </div>
                            <div
                                v-else-if="appsData.length === 0 && appsFetched"
                                class="py-8 text-center"
                            >
                                <p class="text-sm text-muted-foreground">
                                    No apps found for this guest user.
                                </p>
                            </div>
                            <table
                                v-else-if="appsData.length > 0"
                                class="w-full text-sm"
                            >
                                <thead>
                                    <tr
                                        class="border-b text-left text-muted-foreground"
                                    >
                                        <th class="pb-2 font-medium">
                                            App Name
                                        </th>
                                        <th class="pb-2 font-medium">Role</th>
                                        <th class="pb-2 font-medium">
                                            Assigned
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="app in appsData"
                                        :key="app.id"
                                        class="border-b last:border-0"
                                    >
                                        <td class="py-2">
                                            {{ app.appDisplayName }}
                                        </td>
                                        <td class="py-2">
                                            {{ app.roleName ?? '—' }}
                                        </td>
                                        <td class="py-2 text-muted-foreground">
                                            {{ formatDate(app.assignedAt) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Teams Tab -->
                <TabsContent value="teams">
                    <Card>
                        <CardHeader>
                            <CardTitle>Teams Memberships</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                v-if="teamsLoading"
                                class="flex items-center justify-center py-8"
                            >
                                <div
                                    class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"
                                />
                                <span class="ml-2 text-sm text-muted-foreground"
                                    >Loading teams…</span
                                >
                            </div>
                            <div
                                v-else-if="teamsError"
                                class="py-8 text-center"
                            >
                                <p class="text-sm text-muted-foreground">
                                    Unable to load teams. Microsoft Graph API
                                    may be unavailable.
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="mt-3"
                                    @click="retryTab('teams')"
                                    >Retry</Button
                                >
                            </div>
                            <div
                                v-else-if="
                                    teamsData.length === 0 && teamsFetched
                                "
                                class="py-8 text-center"
                            >
                                <p class="text-sm text-muted-foreground">
                                    No teams found for this guest user.
                                </p>
                            </div>
                            <table
                                v-else-if="teamsData.length > 0"
                                class="w-full text-sm"
                            >
                                <thead>
                                    <tr
                                        class="border-b text-left text-muted-foreground"
                                    >
                                        <th class="pb-2 font-medium">
                                            Team Name
                                        </th>
                                        <th class="pb-2 font-medium">
                                            Description
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="team in teamsData"
                                        :key="team.id"
                                        class="border-b last:border-0"
                                    >
                                        <td class="py-2">
                                            {{ team.displayName }}
                                        </td>
                                        <td class="py-2 text-muted-foreground">
                                            {{ team.description ?? '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Sites Tab -->
                <TabsContent value="sites">
                    <Card>
                        <CardHeader>
                            <CardTitle>SharePoint Sites</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                v-if="sitesLoading"
                                class="flex items-center justify-center py-8"
                            >
                                <div
                                    class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"
                                />
                                <span class="ml-2 text-sm text-muted-foreground"
                                    >Loading sites…</span
                                >
                            </div>
                            <div
                                v-else-if="sitesError"
                                class="py-8 text-center"
                            >
                                <p class="text-sm text-muted-foreground">
                                    Unable to load sites. Microsoft Graph API
                                    may be unavailable.
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="mt-3"
                                    @click="retryTab('sites')"
                                    >Retry</Button
                                >
                            </div>
                            <div
                                v-else-if="
                                    sitesData.length === 0 && sitesFetched
                                "
                                class="py-8 text-center"
                            >
                                <p class="text-sm text-muted-foreground">
                                    No sites found for this guest user.
                                </p>
                            </div>
                            <table
                                v-else-if="sitesData.length > 0"
                                class="w-full text-sm"
                            >
                                <thead>
                                    <tr
                                        class="border-b text-left text-muted-foreground"
                                    >
                                        <th class="pb-2 font-medium">
                                            Site Name
                                        </th>
                                        <th class="pb-2 font-medium">URL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="site in sitesData"
                                        :key="site.id"
                                        class="border-b last:border-0"
                                    >
                                        <td class="py-2">
                                            {{ site.displayName }}
                                        </td>
                                        <td class="py-2">
                                            <a
                                                :href="site.webUrl"
                                                target="_blank"
                                                rel="noopener"
                                                class="text-primary hover:underline"
                                            >
                                                {{ site.webUrl }}
                                            </a>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
