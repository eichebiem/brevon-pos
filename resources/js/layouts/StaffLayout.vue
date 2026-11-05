<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Toaster } from '@/components/ui/sonner';
import { logout } from '@/routes';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <!-- Preparation-staff layout: large, readable order cards (prompt §4). -->
    <div class="flex h-svh flex-col bg-background text-foreground">
        <header
            class="flex h-16 shrink-0 items-center gap-4 bg-brevon-navy px-4 text-brevon-cream"
        >
            <AppLogoIcon class="size-10 ring-1 ring-brevon-gold/60" alt="" />
            <div class="leading-tight">
                <p class="font-display text-xl">Preparation</p>
                <p class="text-xs text-brevon-gold-light">
                    {{
                        page.props.location?.current?.name ??
                        'No location assigned'
                    }}
                </p>
            </div>
            <div class="ml-auto flex items-center gap-3">
                <span class="hidden text-sm sm:inline">{{ user.name }}</span>
                <Link
                    :href="logout()"
                    as="button"
                    class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-brevon-gold/50 px-4 text-sm font-medium hover:bg-brevon-navy-dark"
                    @click="router.flushAll()"
                    data-test="staff-logout-button"
                >
                    <LogOut class="size-4" aria-hidden="true" />
                    Log out
                </Link>
            </div>
        </header>
        <main class="flex min-h-0 flex-1 flex-col">
            <slot />
        </main>
        <Toaster />
    </div>
</template>
