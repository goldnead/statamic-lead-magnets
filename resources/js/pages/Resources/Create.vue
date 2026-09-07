<script setup>
/**
 * Eine neue Ressource anlegen.
 *
 * Eine eigene Seite, weil es beim Anlegen noch keinen Datensatz gibt, auf dem
 * eine Detailseite stehen koennte — genau wie „Eintrag erstellen" im Kern.
 * Geaendert wird danach auf der Detailseite selbst, nicht mehr hier.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Alert, Button } from '@statamic/cms/ui';
import ResourceFields from './Fields.vue';
import { toForm, toPayload, visibleFieldKeys } from './form';

const props = defineProps([
    'fileField',   // { blueprint, values, meta } — der assets-Feldtyp fuer die Datei
    'diskWarning', // string | null — der Speicher des Containers ist aus dem Web erreichbar
    'storeUrl',    // POST
]);

const form = reactive(toForm(null));
const fileValues = ref({ ...(props.fileField?.values || {}) });
const formErrors = ref({});

const generalErrors = computed(() => {
    const visible = visibleFieldKeys(form, { creating: true });

    return Object.entries(formErrors.value)
        .filter(([key]) => ! visible.includes(key))
        .map(([, message]) => message);
});

function save() {
    if (! form.title.trim()) return;

    router.post(props.storeUrl, toPayload(form, fileValues.value, { creating: true }), {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    });
}
</script>

<template>
    <Head :title="[__('Create resource'), __('Lead Magnets')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Create resource')" icon="download">
            <Button :text="__('Save')" variant="primary" :disabled="!form.title.trim()" @click="save" />
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
            is-creating
            @update:file-values="(next) => (fileValues = next)"
        />
    </div>
</template>
