<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { create, destroy, edit, index } from '@/routes/admin/roles';

type Row = {
    id: number;
    name: string;
    permissions_count: number;
    users_count: number;
    is_system: boolean;
};

defineProps<{ roles: Row[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Roles', href: index() }] },
});

const pending = ref<Row | null>(null);
const confirmOpen = ref(false);
const processing = ref(false);

function askDelete(row: Row): void {
    pending.value = row;
    confirmOpen.value = true;
}

function confirmDelete(): void {
    if (!pending.value) {
        return;
    }

    router.delete(destroy(pending.value.id).url, {
        onStart: () => (processing.value = true),
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? 'The role was not deleted.',
            ),
        onFinish: () => {
            processing.value = false;
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Roles" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Roles
                </h1>
                <p class="text-sm text-muted-foreground">
                    Permissions are checked on the server for every action.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New role</Link
                >
            </Button>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Roles
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Role
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Permissions
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Users
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="roles.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No roles yet. Run the database seeder.
                        </td>
                    </tr>
                    <tr
                        v-for="row in roles"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 font-medium">
                            {{ row.name }}
                            <span
                                v-if="row.is_system"
                                class="ml-2 rounded-full bg-secondary px-2 py-0.5 text-xs text-secondary-foreground"
                                >built-in</span
                            >
                        </td>
                        <td class="px-4 py-3">{{ row.permissions_count }}</td>
                        <td class="px-4 py-3">{{ row.users_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="edit(row.id)"
                                        ><Pencil aria-hidden="true" />
                                        {{
                                            row.name === 'Owner'
                                                ? 'View'
                                                : 'Edit'
                                        }}<span class="sr-only">
                                            {{ row.name }}</span
                                        ></Link
                                    >
                                </Button>
                                <Button
                                    v-if="!row.is_system"
                                    variant="ghost"
                                    size="sm"
                                    @click="askDelete(row)"
                                >
                                    <Trash2 aria-hidden="true" /> Delete
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="`Delete the ${pending?.name} role?`"
            description="Only roles that no user holds can be deleted. This is recorded in the audit log."
            confirm-label="Delete role"
            destructive
            :processing="processing"
            @confirm="confirmDelete"
        />
    </div>
</template>
