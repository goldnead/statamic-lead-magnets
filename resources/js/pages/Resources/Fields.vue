<script setup>
/**
 * Die Felder einer Ressource — einmal, fuer beide Seiten, die sie zeigen.
 *
 * Die Detailseite IST das Formular (wie beim Collection-Entry), und das
 * Anlegen laeuft ueber dieselben Felder. Zwei Abschriften derselben
 * Feldliste waeren zwei Beschreibungen einer Sache, die auseinanderlaufen,
 * sobald ein Feld dazukommt.
 *
 * Aufbau nach dem Statamic-Vorbild: der graue Grund traegt die Seite, die
 * Felder sitzen auf weissen Karten. Kein grauer Container mit Ueberschrift
 * und weisser Insel darin.
 *
 * `form` wird direkt bearbeitet statt ueber dreizehn `v-model`-Leitungen.
 * Die Auswahl des Datei-Feldtyps liegt daneben, weil sie eine andere Form
 * hat als der Rest und serverseitig an einer eigenen Naht umgepackt wird.
 */
import { computed } from 'vue';
import {
    Alert, Card, Field, Heading, Input, Select, Switch, Textarea,
    PublishContainer, PublishFields, PublishFieldsProvider,
} from '@statamic/cms/ui';

const props = defineProps({
    form: { type: Object, required: true },
    fileValues: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    fileField: { type: Object, default: null },
    diskWarning: { type: String, default: null },
    isCreating: { type: Boolean, default: false },
    readOnly: { type: Boolean, default: false },
});

defineEmits(['update:fileValues']);

const isFile = computed(() => props.form.delivery_type === 'file');

const deliveryOptions = computed(() => [
    { value: 'file', label: __('File') },
    { value: 'link', label: __('Link') },
]);

const fileBlueprintFields = computed(
    () => props.fileField?.blueprint?.tabs?.[0]?.sections?.[0]?.fields ?? [],
);
</script>

<template>
    <!--
        Ein Raster, kein `flex-col` mit `lg:flex-row`: die Utilities dieses
        Addons stehen in einem eigenen Stylesheet, und `flex-col` aus dem
        Kern-Stylesheet gewinnt bei gleicher Spezifitaet ueber die Ladereihenfolge.
        Ein Raster fuegt nur eine Regel hinzu, statt eine ueberschreiben zu muessen.
    -->
    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-4">
            <Card>
                <Heading :text="__('Details')" class="mb-4" />

                <div class="space-y-4">
                    <Field :label="__('Title')" :error="errors.title" :read-only="readOnly">
                        <Input
                            v-model="form.title"
                            :read-only="readOnly"
                            :placeholder="__('e.g. Warm-up routine')"
                        />
                    </Field>

                    <Field :label="__('Description')" :error="errors.description" :read-only="readOnly">
                        <Textarea v-model="form.description" rows="3" :read-only="readOnly" />
                    </Field>
                </div>
            </Card>

            <Card>
                <!-- Nicht `__('Delivery')`: den Schluessel traegt Statamic
                     selbst und uebersetzt ihn mit „Versand", was hier neben
                     „Art der Auslieferung" schief steht. -->
                <Heading :text="__('Delivery and limits')" class="mb-4" />

                <div class="space-y-4">
                    <Field
                        :label="__('Delivery method')"
                        :error="errors.delivery_type"
                        :read-only="readOnly"
                        :instructions="__('A file is streamed from the addon\'s own container. A link forwards the visitor, counted and audited the same way.')"
                    >
                        <Select v-model="form.delivery_type" :options="deliveryOptions" :disabled="readOnly" />
                    </Field>

                    <!--
                        Der Container haelt den Zustand fuer das Dateifeld. Er muss
                        es umschliessen, weil ein Kernfeldtyp seinen Kontext nur von
                        einem Vorfahren bekommt.
                    -->
                    <div v-if="isFile && fileField">
                        <Alert
                            v-if="diskWarning"
                            variant="error"
                            :text="diskWarning"
                            class="mb-4"
                            data-lead-magnets-disk-warning
                        />

                        <PublishContainer
                            name="lead-magnet-file"
                            :blueprint="fileField.blueprint"
                            :meta="fileField.meta || {}"
                            :model-value="fileValues"
                            :track-dirty-state="false"
                            @update:model-value="(next) => $emit('update:fileValues', next)"
                        >
                            <PublishFieldsProvider :fields="fileBlueprintFields">
                                <PublishFields />
                            </PublishFieldsProvider>
                        </PublishContainer>

                        <p v-if="errors.file_asset" class="mt-1 text-xs text-red-500">
                            {{ errors.file_asset }}
                        </p>
                    </div>

                    <Field
                        v-if="!isFile"
                        :label="__('Link URL')"
                        :error="errors.link_url"
                        :read-only="readOnly"
                        :instructions="__('The visitor is forwarded here through the signed route, so a link is counted and audited exactly like a file.')"
                    >
                        <Input v-model="form.link_url" :read-only="readOnly" placeholder="https://…" />
                    </Field>

                    <Field
                        :label="__('Link lifetime (minutes)')"
                        :error="errors.link_ttl"
                        :read-only="readOnly"
                    >
                        <Input
                            v-model="form.link_ttl"
                            type="number"
                            min="1"
                            :read-only="readOnly"
                            :placeholder="__('Leave empty for the configured default')"
                        />
                    </Field>

                    <Field
                        :label="__('Maximum downloads')"
                        :error="errors.max_downloads"
                        :read-only="readOnly"
                    >
                        <Input
                            v-model="form.max_downloads"
                            type="number"
                            min="1"
                            :read-only="readOnly"
                            :placeholder="__('Leave empty for no limit')"
                        />
                    </Field>

                    <Field
                        :label="__('Access lifetime (days)')"
                        :error="errors.grant_ttl_days"
                        :read-only="readOnly"
                    >
                        <Input
                            v-model="form.grant_ttl_days"
                            type="number"
                            min="1"
                            :read-only="readOnly"
                            :placeholder="__('Leave empty for unlimited')"
                        />
                    </Field>
                </div>
            </Card>

            <Card>
                <Heading :text="__('Consent and follow-up')" class="mb-4" />

                <div class="space-y-4">
                    <Field
                        :label="__('Double opt-in')"
                        :error="errors.requires_confirmation"
                        :read-only="readOnly"
                        :instructions="__('On: the address gets a confirmation mail and the download only after confirming. Off: the download goes out immediately.')"
                    >
                        <Switch v-model="form.requires_confirmation" :disabled="readOnly" />
                    </Field>

                    <Field
                        :label="__('Tags to apply')"
                        :error="errors.tags"
                        :read-only="readOnly"
                        :instructions="__('Comma separated. Written onto the contact when access activates. Needs the Leadhub addon; ignored without it.')"
                    >
                        <Input v-model="form.tags" :read-only="readOnly" placeholder="lead-magnet, warm-up" />
                    </Field>

                    <Field
                        :label="__('Mailing list')"
                        :error="errors.marketing_list"
                        :read-only="readOnly"
                        :instructions="__('Subscribes the confirmed address to this list through the Marketing addon, following that list\'s own consent rules. Ignored without the addon.')"
                    >
                        <Input v-model="form.marketing_list" :read-only="readOnly" placeholder="newsletter" />
                    </Field>
                </div>
            </Card>
        </div>

        <!-- Die Seitenspalte wie beim Entry: der Schalter oben, die Kennung darunter. -->
        <div class="space-y-4">
            <Card>
                <Field
                    :label="__('Published')"
                    :error="errors.published"
                    :read-only="readOnly"
                    :instructions="__('An unpublished resource cannot be requested. Access already granted keeps working.')"
                >
                    <Switch v-model="form.published" :disabled="readOnly" />
                </Field>
            </Card>

            <Card>
                <Field
                    :label="__('Handle')"
                    :error="errors.handle"
                    :read-only="readOnly || !isCreating"
                    :instructions="isCreating
                        ? __('Lowercase letters, numbers, dashes and underscores. This is what the request form names, and it has to be unique across every brand. Leave empty to generate it from the title.')
                        : __('Named by the request form, and fixed once the resource exists: changing it would orphan every access already granted.')"
                >
                    <Input
                        v-model="form.handle"
                        :read-only="readOnly || !isCreating"
                        placeholder="warm_up_routine"
                    />
                </Field>
            </Card>
        </div>
    </div>
</template>
