<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, selectClass } from '@/lib/format';
import { logs } from '@/routes/admin/system';

// FR-102, D-184: health and recent logs for technical administrators.
type Entry = {
    at: string | null;
    level: string;
    message: string;
    details: string;
};

const props = defineProps<{
    health: {
        environment: string;
        debug: boolean;
        https: boolean;
        php: string;
        laravel: string;
        database: boolean;
        log_file: string | null;
        log_size: number;
        last_backup: { name: string; at: string; size: number } | null;
    };
    entries: Entry[];
    levels: string[];
    filters: { level?: string; search?: string };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'System logs', href: logs() }] },
});

const form = reactive({
    level: props.filters.level ?? '',
    search: props.filters.search ?? '',
});

function apply(): void {
    router.get(
        logs().url,
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}

function tone(level: string): 'danger' | 'warning' | 'info' | 'neutral' {
    if (['emergency', 'alert', 'critical', 'error'].includes(level)) {
        return 'danger';
    }
    if (level === 'warning') {
        return 'warning';
    }

    return level === 'debug' ? 'neutral' : 'info';
}

// A backup older than two days means the daily job has stopped (D-185).
const backupStale = computed(
    () =>
        props.health.last_backup === null ||
        Date.now() - new Date(props.health.last_backup.at).getTime() >
            2 * 24 * 60 * 60 * 1000,
);

function size(bytes: number): string {
    return bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.ceil(bytes / 1024)} KB`;
}
</script>

<template>
    <Head title="System logs" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                System logs
            </h1>
            <p class="text-sm text-muted-foreground">
                Health check and the latest technical log entries. Passwords,
                keys and tokens are hidden.
            </p>
        </header>

        <section
            aria-labelledby="health-heading"
            class="rounded-xl border border-border bg-card p-4"
        >
            <h2 id="health-heading" class="mb-3 font-semibold">Health</h2>
            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-muted-foreground">Environment</dt>
                    <dd class="font-medium">{{ health.environment }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Database</dt>
                    <dd>
                        <StatusBadge
                            :tone="health.database ? 'success' : 'danger'"
                        >
                            {{
                                health.database ? 'Connected' : 'Not reachable'
                            }}
                        </StatusBadge>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Debug mode</dt>
                    <dd>
                        <StatusBadge
                            :tone="
                                health.debug &&
                                health.environment === 'production'
                                    ? 'danger'
                                    : health.debug
                                      ? 'warning'
                                      : 'success'
                            "
                        >
                            {{ health.debug ? 'On' : 'Off' }}
                        </StatusBadge>
                        <span
                            v-if="
                                health.debug &&
                                health.environment === 'production'
                            "
                            class="ml-2 text-xs text-red-800 dark:text-red-300"
                            >Turn off (APP_DEBUG=false)</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">HTTPS required</dt>
                    <dd>
                        <StatusBadge
                            :tone="health.https ? 'success' : 'neutral'"
                        >
                            {{ health.https ? 'Yes' : 'No' }}
                        </StatusBadge>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">PHP / Laravel</dt>
                    <dd class="font-medium">
                        {{ health.php }} / {{ health.laravel }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Last backup</dt>
                    <dd>
                        <StatusBadge
                            :tone="backupStale ? 'warning' : 'success'"
                        >
                            {{
                                health.last_backup
                                    ? formatDateTime(health.last_backup.at)
                                    : 'None yet'
                            }}
                        </StatusBadge>
                        <span
                            v-if="health.last_backup"
                            class="ml-2 text-xs text-muted-foreground"
                            >{{ size(health.last_backup.size) }}</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Log file</dt>
                    <dd class="font-medium">
                        {{
                            health.log_file
                                ? `${health.log_file} (${size(health.log_size)})`
                                : 'None yet'
                        }}
                    </dd>
                </div>
            </dl>
        </section>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="apply"
        >
            <div class="grid gap-1">
                <label for="level" class="text-xs font-medium">Level</label>
                <select id="level" v-model="form.level" :class="selectClass">
                    <option value="">All</option>
                    <option v-for="l in levels" :key="l" :value="l">
                        {{ l }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="search" class="text-xs font-medium">Contains</label>
                <Input id="search" v-model="form.search" />
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <section aria-labelledby="entries-heading" class="grid gap-3">
            <h2 id="entries-heading" class="sr-only">Log entries</h2>
            <p
                v-if="entries.length === 0"
                class="rounded-xl border border-border bg-card px-4 py-10 text-center text-muted-foreground"
            >
                No log entries match these filters.
            </p>
            <article
                v-for="(entry, i) in entries"
                :key="i"
                class="rounded-xl border border-border bg-card p-4"
            >
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <StatusBadge :tone="tone(entry.level)">{{
                        entry.level
                    }}</StatusBadge>
                    <span class="text-muted-foreground">{{
                        formatDateTime(entry.at)
                    }}</span>
                </div>
                <p class="mt-2 text-sm break-words">{{ entry.message }}</p>
                <details v-if="entry.details" class="mt-2">
                    <summary class="cursor-pointer text-xs font-medium">
                        Details
                    </summary>
                    <pre
                        class="mt-2 max-h-80 overflow-auto rounded-md bg-muted p-3 text-xs whitespace-pre-wrap"
                        >{{ entry.details }}</pre>
                </details>
            </article>
        </section>
    </div>
</template>
