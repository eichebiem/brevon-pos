<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { MapPin } from '@lucide/vue';
import { computed } from 'vue';
import { switchMethod } from '@/routes/location';

const page = usePage();
const location = computed(() => page.props.location);

function change(event: Event): void {
    const id = Number((event.target as HTMLSelectElement).value);
    router.post(
        switchMethod().url,
        { branch_id: id },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div
        v-if="location"
        class="flex items-center gap-2 rounded-lg bg-sidebar-accent px-2 py-1.5 text-sidebar-accent-foreground group-data-[collapsible=icon]:hidden"
    >
        <MapPin
            class="size-4 shrink-0 text-brevon-gold-light"
            aria-hidden="true"
        />
        <span v-if="!location.current" class="text-xs"
            >No location assigned</span
        >
        <span
            v-else-if="location.available.length === 1"
            class="truncate text-xs"
            :title="location.current.name"
            >{{ location.current.code }} · {{ location.current.name }}</span
        >
        <template v-else>
            <label for="location-switcher" class="sr-only"
                >Working location</label
            >
            <select
                id="location-switcher"
                class="w-full truncate rounded bg-transparent text-xs focus-visible:outline-2 focus-visible:outline-brevon-gold-light"
                :value="location.current.id"
                @change="change"
            >
                <option
                    v-for="option in location.available"
                    :key="option.id"
                    :value="option.id"
                    class="text-foreground"
                >
                    {{ option.code }} · {{ option.name
                    }}{{ option.type === 'pop_up' ? ' (pop-up)' : '' }}
                </option>
            </select>
        </template>
    </div>
</template>
