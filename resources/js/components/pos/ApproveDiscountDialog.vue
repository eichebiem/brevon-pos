<script setup lang="ts">
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/format';

const open = defineModel<boolean>('open', { required: true });
defineProps<{
    name: string | null;
    amount: string | null;
    error: string | undefined;
}>();
const emit = defineEmits<{
    approve: [approver: { username: string; password: string }];
}>();

const username = ref('');
const password = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        username.value = '';
        password.value = '';
    }
});

function approve(): void {
    if (username.value.trim() === '' || password.value === '') {
        return;
    }
    emit('approve', {
        username: username.value.trim(),
        password: password.value,
    });
    password.value = '';
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle class="font-display text-2xl"
                    >Manager approval</DialogTitle
                >
                <DialogDescription>
                    {{ name }} · {{ formatMoney(amount) }} off. A manager signs
                    in here to approve it for this sale only.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" novalidate @submit.prevent="approve">
                <div class="grid gap-1">
                    <Label for="approver-username">Manager username</Label>
                    <Input
                        id="approver-username"
                        v-model="username"
                        autocomplete="off"
                        autocapitalize="none"
                        data-test="pos-approver-username"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="approver-password">Password</Label>
                    <Input
                        id="approver-password"
                        v-model="password"
                        type="password"
                        autocomplete="off"
                        data-test="pos-approver-password"
                    />
                </div>
                <InputError :message="error" />
                <DialogFooter>
                    <Button
                        type="submit"
                        class="min-h-11"
                        :disabled="username.trim() === '' || password === ''"
                        data-test="pos-approver-confirm"
                        >Approve</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
