<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import AuthSimpleLayout from '@/layouts/auth/AuthSimpleLayout.vue';
import { home } from '@/routes';

// Friendly error page (NFR-020, D-183): no technical details are shown.
const props = defineProps<{ status: number }>();

const messages: Record<number, { title: string; description: string }> = {
    403: {
        title: 'Not allowed',
        description:
            'Your account does not have access to this page. Ask a manager if you need it.',
    },
    404: {
        title: 'Page not found',
        description:
            'This page does not exist, or it is at a location you are not assigned to.',
    },
    429: {
        title: 'Too many tries',
        description: 'Please wait a minute, then try again.',
    },
    500: {
        title: 'Something went wrong',
        description:
            'Nothing was saved from the last step. Please try again. If it keeps happening, tell a manager.',
    },
    503: {
        title: 'Back soon',
        description:
            'Brevon POS is being updated. Please try again in a few minutes.',
    },
};

const message = computed(() => messages[props.status] ?? messages[500]);

function goBack(): void {
    window.history.back();
}
</script>

<template>
    <Head :title="message.title" />
    <AuthSimpleLayout :title="message.title" :description="message.description">
        <div class="flex flex-col gap-3">
            <Button as-child>
                <Link :href="home()">Go to the start page</Link>
            </Button>
            <Button variant="outline" type="button" @click="goBack">
                Go back
            </Button>
            <p class="text-center text-xs text-muted-foreground">
                Error {{ status }}
            </p>
        </div>
    </AuthSimpleLayout>
</template>
