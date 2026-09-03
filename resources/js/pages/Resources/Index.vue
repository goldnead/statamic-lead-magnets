<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Alert, Badge, Button, DropdownItem, ConfirmationModal, CommandPaletteItem,
} from '@statamic/cms/ui';

const props = defineProps([
    'resources',  // [{ id, handle, title, delivery_type, requires_confirmation, published, active, pending, show_url, edit_url, delete_url }]
    'columns',    // Array<Column>
    'createUrl',  // string
    'canManage',  // bool
]);

const toDelete = ref(null);

// A refused delete used to be silent on screens like this: the response came
// back with errors, the row stayed, and nothing said why. There is no field
// here to hang a message on, so everything that comes back is shown above the
// listing.
const formErrors = ref({});

const generalErrors = computed(() => Object.values(formErrors.value));

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function destroy() {
    if (! toDelete.value) return;

    router.delete(toDelete.value.delete_url, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
        onFinish: () => { toDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('Resources'), __('Lead Magnets')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Lead Magnets')" icon="download">
            <CommandPaletteItem
                v-if="canManage"
                :text="__('Create resource')"
                :url="createUrl"
                category="actions"
            />
            <Button
                v-if="canManage"
                :href="createUrl"
                :text="__('Create resource')"
                variant="primary"
            />
        </Header>

        <!-- An error banner is an `Alert`, not a red div on a bare Panel. -->
        <Alert
            v-for="(message, index) in generalErrors"
            :key="index"
            variant="error"
            :text="message"
            class="mb-4"
            data-lead-magnets-form-errors
        />

        <Listing
            :items="resources"
            :columns="columns"
            preferences-prefix="lead-magnets.resources"
            @refreshing="reloadPage"
        >
            <template #cell-title="{ row }">
                <Link :href="row.show_url" class="font-medium hover:underline">{{ row.title }}</Link>
            </template>

            <template #cell-handle="{ row }">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ row.handle }}</span>
            </template>

            <template #cell-delivery_type="{ row }">
                <Badge
                    :color="row.delivery_type === 'file' ? 'blue' : 'default'"
                    :text="row.delivery_type === 'file' ? __('File') : __('Link')"
                />
            </template>

            <template #cell-requires_confirmation="{ row }">
                <Badge
                    :color="row.requires_confirmation ? 'green' : 'default'"
                    :text="row.requires_confirmation ? __('On') : __('Off')"
                />
            </template>

            <template #cell-active="{ row }">
                <Badge color="green" :text="String(row.active)" />
            </template>

            <template #cell-pending="{ row }">
                <Badge color="default" :text="String(row.pending)" />
            </template>

            <template #cell-published="{ row }">
                <Badge
                    :color="row.published ? 'green' : 'default'"
                    :text="row.published ? __('Published') : __('Draft')"
                />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem v-if="canManage" :text="__('Edit')" icon="edit" :href="row.edit_url" />
                <DropdownItem v-if="canManage" :text="__('Delete')" icon="trash" @click="toDelete = row" />
            </template>
        </Listing>

        <ConfirmationModal
            :open="toDelete !== null"
            :title="__('Delete resource')"
            :body-text="__('Delete this resource, every grant on it and their download history? This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="toDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
