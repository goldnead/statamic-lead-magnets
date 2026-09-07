<script setup>
/**
 * Die Detailseite einer Ressource — und zugleich ihr Formular.
 *
 * Vorher fuehrte ein Knopf „Bearbeiten" auf eine eigene Seite, waehrend die
 * Detailseite nur Pillen zeigte. Beim Collection-Entry gibt es diesen Bruch
 * nicht: die Detailseite ist das Formular, gespeichert wird oben rechts, und
 * Loeschen sitzt im „…"-Menue daneben. Genau so hier.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Heading, Listing, Alert, Badge, Button, Dropdown, DropdownMenu, DropdownItem,
    ConfirmationModal,
} from '@statamic/cms/ui';
import ResourceFields from './Fields.vue';
import { toForm, toPayload, visibleFieldKeys } from './form';

const props = defineProps([
    'resource',      // { id, handle, title, description, delivery_type, link_url, requires_confirmation, published, link_ttl, max_downloads, grant_ttl_days, tags, marketing_list }
    'grants',        // [{ id, email, state, requested_at, confirmed_at, delivered_at, downloads, lapsed, revoke_url, reinstate_url, resend_url }]
    'columns',       // Array<Column>
    'states',        // list of the six entitlement states
    'filters',       // { state, search }
    'pagination',    // { current_page, last_page, total }
    'fileField',     // { blueprint, values, meta } | null ohne Aenderungsrecht
    'diskWarning',   // string | null
    'updateUrl',     // PATCH | null ohne Aenderungsrecht
    'deleteUrl',     // DELETE | null ohne Aenderungsrecht
    'canManage',
    'canManageGrants',
]);

const form = reactive(toForm(props.resource));
const fileValues = ref({ ...(props.fileField?.values || {}) });

const showDeleteConfirm = ref(false);
const toRevoke = ref(null);
const formErrors = ref({});

// Was kein eigenes Feld auf dem Schirm hat — die Fehler der Zugangs-Aktionen
// zum Beispiel — waere sonst unsichtbar und steht deshalb oben im Banner.
const generalErrors = computed(() => {
    const visible = visibleFieldKeys(form);

    return Object.entries(formErrors.value)
        .filter(([key]) => ! visible.includes(key))
        .map(([, message]) => message);
});

const requestOptions = {
    preserveScroll: true,
    onError: (errors) => { formErrors.value = errors || {}; },
    onSuccess: () => { formErrors.value = {}; },
};

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function save() {
    if (! form.title.trim()) return;

    router.patch(props.updateUrl, toPayload(form, fileValues.value), requestOptions);
}

function destroy() {
    router.delete(props.deleteUrl, {
        onError: (errors) => { formErrors.value = errors || {}; },
    });
}

function post(url) {
    router.post(url, {}, requestOptions);
}

function revoke() {
    if (! toRevoke.value) return;
    const url = toRevoke.value.revoke_url;
    toRevoke.value = null;
    post(url);
}

// Die sechs Zustaende aus entitlements, nicht die vier, die dieses Addon
// schreibt. Ein Zugang kann von dort eine Karenz oder ein Startdatum bekommen,
// und eine Plakette ohne Farbe liest sich als Fehler statt als Zustand.
const stateColors = {
    active: 'green',
    grace_period: 'green',
    pending: 'default',
    scheduled: 'default',
    revoked: 'red',
    expired: 'orange',
};

function formatDate(value) {
    if (! value) return '—';
    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head :title="[resource.title, __('Lead Magnets')]" />

    <div class="max-w-page mx-auto">
        <!--
            Kernreihenfolge: erst das „…"-Menue, die Hauptaktion zuletzt. Loeschen
            ist ein `DropdownItem variant="destructive"`, kein `Button
            variant="danger"` — `danger` gehoert im Kern auf den Bestaetigungsknopf
            im Dialog.
        -->
        <Header :title="form.title || resource.title" icon="download">
            <Dropdown v-if="deleteUrl">
                <DropdownMenu>
                    <DropdownItem
                        :text="__('Delete')"
                        icon="trash"
                        variant="destructive"
                        @click="showDeleteConfirm = true"
                    />
                </DropdownMenu>
            </Dropdown>
            <Button
                v-if="updateUrl"
                :text="__('Save')"
                variant="primary"
                :disabled="!form.title.trim()"
                @click="save"
            />
        </Header>

        <!-- Ein Fehlerbanner ist ein `Alert`, kein rotes div auf einer nackten Flaeche. -->
        <Alert
            v-for="(message, index) in generalErrors"
            :key="index"
            variant="error"
            :text="message"
            class="mb-4"
            data-lead-magnets-form-errors
        />

        <ResourceFields
            :form="form"
            :file-values="fileValues"
            :errors="formErrors"
            :file-field="fileField"
            :disk-warning="diskWarning"
            :read-only="!canManage"
            @update:file-values="(next) => (fileValues = next)"
        />

        <Heading :text="__('Access')" class="mt-8 mb-2" />

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

        <ConfirmationModal
            :open="toRevoke !== null"
            :title="__('Revoke access')"
            :body-text="__('Revoke this access? Links already sent stop working immediately.')"
            danger
            :button-text="__('Revoke')"
            @cancel="toRevoke = null"
            @confirm="revoke"
        />

        <ConfirmationModal
            :open="showDeleteConfirm"
            :title="__('Delete resource')"
            :body-text="__('Delete this resource, every grant on it and their download history? This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
