<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, ClipboardList, Shield, Users } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login, register } from '@/routes';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const features = [
    {
        icon: Building2,
        label: 'Partner Orgs',
        description:
            'Manage external partner organizations and cross-tenant relationships',
    },
    {
        icon: Shield,
        label: 'Access Policies',
        description: 'Configure cross-tenant access and MFA trust settings',
    },
    {
        icon: Users,
        label: 'Guest Lifecycle',
        description: 'Invite, track, and manage B2B guest users across tenants',
    },
    {
        icon: ClipboardList,
        label: 'Activity Audit',
        description: 'Full audit trail of all partner and guest operations',
    },
];
</script>

<template>
    <Head title="Partner365 — M365 Partner Management">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <div class="flex min-h-screen flex-col bg-background">
        <!-- Header -->
        <header class="flex items-center justify-between px-6 py-4 lg:px-8">
            <div class="flex items-center gap-2.5">
                <AppLogoIcon
                    class="size-8 text-indigo-600 dark:text-indigo-400"
                />
                <span class="text-lg font-semibold text-foreground"
                    >Partner365</span
                >
            </div>
            <nav class="flex items-center gap-3">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500"
                    >
                        Sign in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="rounded-lg border border-border px-4 py-2 text-sm font-medium text-foreground transition hover:bg-accent"
                    >
                        Register
                    </Link>
                </template>
            </nav>
        </header>

        <!-- Hero -->
        <main
            class="flex flex-1 flex-col items-center justify-center px-6 py-16 lg:py-24"
        >
            <div class="relative flex flex-col items-center text-center">
                <!-- Subtle radial gradient background -->
                <div
                    class="pointer-events-none absolute -top-32 h-96 w-96 rounded-full bg-indigo-100 opacity-40 blur-3xl dark:bg-indigo-900 dark:opacity-20"
                />

                <div class="relative">
                    <AppLogoIcon
                        class="mx-auto size-16 text-indigo-600 lg:size-20 dark:text-indigo-400"
                    />
                    <h1
                        class="mt-6 text-4xl font-bold tracking-tight text-foreground lg:text-5xl"
                    >
                        Partner365
                    </h1>
                    <p
                        class="mx-auto mt-4 max-w-md text-lg text-muted-foreground"
                    >
                        Manage M365 partner organizations, cross-tenant
                        policies, and guest users.
                    </p>
                    <div class="mt-8">
                        <Link
                            :href="
                                $page.props.auth.user ? dashboard() : login()
                            "
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-3 text-sm font-medium text-white transition hover:bg-indigo-500"
                        >
                            Get Started
                            <svg
                                class="size-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="2"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"
                                />
                            </svg>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Feature pills -->
            <div
                class="mt-16 grid max-w-2xl grid-cols-2 gap-4 lg:grid-cols-4 lg:gap-6"
            >
                <div
                    v-for="feature in features"
                    :key="feature.label"
                    class="flex flex-col items-center rounded-xl border border-border bg-card p-5 text-center shadow-sm"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-950"
                    >
                        <component
                            :is="feature.icon"
                            class="size-5 text-indigo-600 dark:text-indigo-400"
                        />
                    </div>
                    <h3 class="mt-3 text-sm font-semibold text-foreground">
                        {{ feature.label }}
                    </h3>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ feature.description }}
                    </p>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="px-6 py-6 text-center text-xs text-muted-foreground">
            &copy; {{ new Date().getFullYear() }} Partner365
        </footer>
    </div>
</template>
