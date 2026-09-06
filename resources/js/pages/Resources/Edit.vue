<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Alert, Button, Dropdown, DropdownMenu, DropdownItem,
    Field, Input, Select, Textarea, Switch, ConfirmationModal,
    PublishContainer, PublishFields, PublishFieldsProvider,
} from '@statamic/cms/ui';

const props = defineProps([
    'resource',    // { … } | null on create
    'fileField',   // { blueprint, values, meta } — the assets fieldtype for the file
    'diskWarning', // string | null — the container's disk is reachable from the web
    'storeUrl',    // POST (create only)
    'updateUrl',   // PATCH (edit only)
    'deleteUrl',   // DELETE (edit only)
]);

const isCreating = computed(() => ! props.updateUrl);

const title = ref(props.resource?.title || '');
const handle = ref(props.resource?.handle || '');
const description = ref(props.resource?.description || '');
const deliveryType = ref(props.resource?.delivery_type || 'file');
const linkUrl = ref(props.resource?.link_url || '');
const requiresConfirmation = ref(props.resource ? !! props.resource.requires_confirmation : true);
const published = ref(props.resource ? !! props.resource.published : true);
const linkTtl = ref(props.resource?.link_ttl ?? '');
const maxDownloads = ref(props.resource?.max_downloads ?? '');
const grantTtlDays = ref(props.resource?.grant_ttl_days ?? '');
const tags = ref((props.resource?.tags || []).join(', '));
const marketingList = ref(props.resource?.marketing_list || '');

const showDeleteConfirm = ref(false);

const deliveryOptions = computed(() => [
    { value: 'file', label: __('File') },
    { value: 'link', label: __('Link') },
]);

const isFile = computed(() => deliveryType.value === 'file');

// Die Datei laeuft ueber Statamics eigenen `assets`-Feldtyp, gefahren durch
// einen `PublishContainer` — der einzige Weg, auf dem ein Kernfeldtyp
// ausserhalb eines Publish-Formulars seinen Kontext bekommt, und derselbe, den
// die Schrittauswahl im Funnels-Addon nimmt.
//
// Gespeichert wird weiter ein Pfad auf einer Platte, weil das die
// Download-Route ausliefert. Der Feldtyp spricht Asset-IDs und Listen; umgepackt
// wird an genau dieser Naht, hin serverseitig, zurueck hier.
const fileValues = ref({ ...(props.fileField?.values || {}) });

const fileBlueprintFields = computed(
    () => props.fileField?.blueprint?.tabs?.[0]?.sections?.[0]?.fields ?? [],
);

const fileAssetId = computed(() => (fileValues.value.file_asset || [])[0] ?? null);

function number(value) {
    const trimmed = String(value ?? '').trim();
    return trimmed === '' ? null : Number(trimmed);
}

function payload() {
    return {
        title: title.value,
        ...(isCreating.value ? { handle: handle.value || null } : {}),
        description: description.value || null,
        delivery_type: deliveryType.value,
        file_asset: isFile.value ? fileAssetId.value : null,
        link_url: isFile.value ? null : (linkUrl.value || null),
        requires_confirmation: requiresConfirmation.value,
        published: published.value,
        link_ttl: number(linkTtl.value),
        max_downloads: number(maxDownloads.value),
        grant_ttl_days: number(grantTtlDays.value),
        tags: tags.value.split(',').map((t) => t.trim()).filter(Boolean),
        marketing_list: marketingList.value || null,
    };
}

const formErrors = ref({});

// Keys rendered next to their own field. Anything else has no field to sit at
// and goes into the summary above the form, or it would be invisible.
const fieldKeys = [
    'title', 'handle', 'description', 'delivery_type', 'file_asset', 'link_url',
    'requires_confirmation', 'published', 'link_ttl', 'max_downloads', 'grant_ttl_days',
    'tags', 'marketing_list',
];

// Which of those keys actually has a field on screen right now. The handle
// input only exists while creating, and the file/link inputs swap with the
// delivery type — a rejected key whose field is hidden has to reach the
// summary or it is shown nowhere at all.
const keysWithAVisibleField = computed(() => fieldKeys.filter((key) => {
    if (key === 'handle') return isCreating.value;
    if (key === 'file_asset') return isFile.value;
    if (key === 'link_url') return ! isFile.value;
    return true;
}));

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(([key]) => ! keysWithAVisibleField.value.includes(key))
        .map(([, message]) => message)
);

function save() {
    if (! title.value.trim()) return;

    const options = {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    };

    if (isCreating.value) {
        router.post(props.storeUrl, payload(), options);
    } else {
        router.patch(props.updateUrl, payload(), options);
    }
}

function destroy() {
    router.delete(props.deleteUrl, {
        onError: (errors) => { formErrors.value = errors || {}; },
    });
}
</script>

<template>
    <Head :title="[isCreating ? __('Create resource') : resource.title, __('Lead Magnets')]" />

    <div class="max-w-3xl mx-auto">
        <!--
            Core order: the "…" menu first, the primary action last. Delete is a
            `DropdownItem variant="destructive"` and not a `Button
            variant="danger"` — core uses `danger` only as the confirm button
            inside a modal, and Dropdown renders its own dots trigger.
        -->
        <Header :title="isCreating ? __('Create resource') : title" icon="download">
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
            <Button :text="__('Save')" variant="primary" :disabled="!title.trim()" @click="save" />
        </Header>

        <!-- An error banner is an `Alert`, not a red div dropped onto a bare
             Panel. The same shape shipped in four addons of this family. -->
        <Alert
            v-for="(message, index) in generalErrors"
            :key="index"
            variant="error"
            :text="message"
            class="mb-4"
            data-lead-magnets-form-errors
        />

        <Panel :heading="__('Details')">
            <Card>
                <div class="space-y-4">
                    <Field :label="__('Title')" :error="formErrors.title">
                        <Input v-model="title" :placeholder="__('e.g. Warm-up routine')" />
                    </Field>

                    <Field v-if="isCreating" :label="__('Handle')" :error="formErrors.handle">
                        <Input v-model="handle" placeholder="warm_up_routine" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Lowercase letters, numbers, dashes and underscores. This is what the request form names, and it has to be unique across every brand. Leave empty to generate it from the title.') }}
                        </p>
                    </Field>

                    <Field :label="__('Description')" :error="formErrors.description">
                        <Textarea v-model="description" rows="3" />
                    </Field>

                    <Field :label="__('Published')" :error="formErrors.published">
                        <Switch v-model="published" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('An unpublished resource cannot be requested. Access already granted keeps working.') }}
                        </p>
                    </Field>
                </div>
            </Card>
        </Panel>

        <Panel :heading="__('Delivery')" class="mt-4">
            <Card>
                <div class="space-y-4">
                    <Field :label="__('Delivery')" :error="formErrors.delivery_type">
                        <Select v-model="deliveryType" :options="deliveryOptions" />
                    </Field>

                    <!-- Der Container haelt den Zustand fuer das Dateifeld. Er
                         muss es umschliessen, weil ein Kernfeldtyp seinen
                         Kontext nur von einem Vorfahren bekommt. -->
                    <div v-if="isFile">
                        <Alert
                            v-if="diskWarning"
                            variant="error"
                            :text="diskWarning"
                            class="mb-4"
                            data-lead-magnets-disk-warning
                        />

                        <PublishContainer
                            name="lead-magnet-file"
                            :blueprint="fileField?.blueprint"
                            :meta="fileField?.meta || {}"
                            :model-value="fileValues"
                            :track-dirty-state="false"
                            @update:model-value="(next) => (fileValues = next)"
                        >
                            <PublishFieldsProvider :fields="fileBlueprintFields">
                                <PublishFields />
                            </PublishFieldsProvider>
                        </PublishContainer>

                        <p v-if="formErrors.file_asset" class="mt-1 text-xs text-red-500">
                            {{ formErrors.file_asset }}
                        </p>
                    </div>

                    <Field v-if="!isFile" :label="__('Link URL')" :error="formErrors.link_url">
                        <Input v-model="linkUrl" placeholder="https://…" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('The visitor is forwarded here through the signed route, so a link is counted and audited exactly like a file.') }}
                        </p>
                    </Field>

                    <Field :label="__('Link lifetime (minutes)')" :error="formErrors.link_ttl">
                        <Input v-model="linkTtl" type="number" min="1" :placeholder="__('Leave empty for the configured default')" />
                    </Field>

                    <Field :label="__('Maximum downloads')" :error="formErrors.max_downloads">
                        <Input v-model="maxDownloads" type="number" min="1" :placeholder="__('Leave empty for no limit')" />
                    </Field>

                    <Field :label="__('Access lifetime (days)')" :error="formErrors.grant_ttl_days">
                        <Input v-model="grantTtlDays" type="number" min="1" :placeholder="__('Leave empty for unlimited')" />
                    </Field>
                </div>
            </Card>
        </Panel>

        <Panel :heading="__('Consent and follow-up')" class="mt-4">
            <Card>
                <div class="space-y-4">
                    <Field :label="__('Double opt-in')" :error="formErrors.requires_confirmation">
                        <Switch v-model="requiresConfirmation" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('On: the address gets a confirmation mail and the download only after confirming. Off: the download goes out immediately.') }}
                        </p>
                    </Field>

                    <Field :label="__('Tags to apply')" :error="formErrors.tags">
                        <Input v-model="tags" placeholder="lead-magnet, warm-up" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Comma separated. Written onto the contact when access activates. Needs the Leadhub addon; ignored without it.') }}
                        </p>
                    </Field>

                    <Field :label="__('Mailing list')" :error="formErrors.marketing_list">
                        <Input v-model="marketingList" placeholder="newsletter" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Subscribes the confirmed address to this list through the Marketing addon, following that list\'s own consent rules. Ignored without the addon.') }}
                        </p>
                    </Field>
                </div>
            </Card>
        </Panel>

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
