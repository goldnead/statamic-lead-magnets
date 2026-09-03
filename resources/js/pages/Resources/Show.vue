<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Panel, Card, Alert, Badge, Button, DropdownItem, ConfirmationModal, Description,
} from '@statamic/cms/ui';

const props = defineProps([
    'resource',          // { id, handle, title, description, delivery_type, requires_confirmation, published }
    'grants',            // [{ id, email, state, requested_at, confirmed_at, delivered_at, downloads, lapsed, revoke_url, reinstate_url, resend_url }]
    'columns',           // Array<Column>
    'states',            // list of the six entitlement states
    'filters',           // { state, search }
    'pagination',        // { current_page, last_page, total }
    'editUrl',
    'canManage',
    'canManageGrants',
]);

const toRevoke = ref(null);
const formErrors = ref({});

const generalErrors = computed(() => Object.values(formErrors.value));

// The six entitlement states, not the four this addon writes. An operator can
// give a grant a start date or a grace period from the entitlements screen, and
// a badge with no colour for those reads as a rendering bug rather than a state.
const stateColors = {
    active: 'green',
    grace_period: 'green',
    pending: 'default',
    scheduled: 'default',
    revoked: 'red',
    expired: 'orange',
};

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function post(url) {
    router.post(url, {}, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    });
}

function revoke() {
    if (! toRevoke.value) return;
    const url = toRevoke.value.revoke_url;
    toRevoke.value = null;
    post(url);
}

function formatDate(value) {
    if (! value) return '—';
    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head :title="[resource.title, __('Lead Magnets')]" />

    <div class="max-w-page mx-auto">
        <Header :title="resource.title" icon="download">
            <Button v-if="canManage" :href="editUrl" :text="__('Edit')" />
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

        <Panel :heading="__('Details')" class="mb-4">
            <Card>
                <div class="space-y-2 text-sm">
                    <p class="text-gray-500 dark:text-gray-400">{{ resource.handle }}</p>
                    <Description v-if="resource.description" :text="resource.description" />
                    <div class="flex gap-2 pt-1">
                        <Badge
                            :color="resource.delivery_type === 'file' ? 'blue' : 'default'"
                            :text="resource.delivery_type === 'file' ? __('File') : __('Link')"
                        />
                        <Badge
                            :color="resource.requires_confirmation ? 'green' : 'default'"
                            :text="resource.requires_confirmation ? __('Double opt-in on') : __('Double opt-in off')"
                        />
                        <Badge
                            :color="resource.published ? 'green' : 'default'"
                            :text="resource.published ? __('Published') : __('Draft')"
                        />
                    </div>
                </div>
            </Card>
        </Panel>

        <Panel :heading="__('Access')">
            <Listing
                :items="grants"
                :columns="columns"
                preferences-prefix="lead-magnets.grants"
                @refreshing="reloadPage"
            >
                <template #cell-email="{ row }">
                    <span class="font-medium">{{ row.email }}</span>
                </template>

                <template #cell-state="{ row }">
                    <Badge :color="stateColors[row.state] || 'default'" :text="__(row.state)" />
                </template>

                <template #cell-requested_at="{ row }">
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(row.requested_at) }}</span>
                </template>

                <template #cell-confirmed_at="{ row }">
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(row.confirmed_at) }}</span>
                </template>

                <template #cell-downloads="{ row }">
                    <Badge color="default" :text="String(row.downloads)" />
                </template>

                <template #prepended-row-actions="{ row }">
                    <DropdownItem
                        v-if="canManageGrants && row.state === 'active'"
                        :text="__('Send the link again')"
                        icon="mail"
                        @click="post(row.resend_url)"
                    />
                    <DropdownItem
                        v-if="canManageGrants && (row.state === 'revoked' || row.state === 'expired')"
                        :text="__('Reinstate access')"
                        icon="sync"
                        @click="post(row.reinstate_url)"
                    />
                    <DropdownItem
                        v-if="canManageGrants && row.state !== 'revoked'"
                        :text="__('Revoke access')"
                        icon="trash"
                        @click="toRevoke = row"
                    />
                </template>
            </Listing>
        </Panel>

        <ConfirmationModal
            :open="toRevoke !== null"
            :title="__('Revoke access')"
            :body-text="__('Revoke this access? Links already sent stop working immediately.')"
            danger
            :button-text="__('Revoke')"
            @cancel="toRevoke = null"
            @confirm="revoke"
        />
    </div>
</template>
