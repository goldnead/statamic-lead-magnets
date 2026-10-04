/**
 * Der Umweg zwischen Datensatz und Formular, einmal fuer beide Seiten.
 *
 * Zahlenfelder kommen als Text aus einem `<input>` zurueck und muessen leer
 * von 0 unterscheiden koennen; Tags sind in der Maske eine Zeile und im
 * Datensatz eine Liste. Beides zweimal zu schreiben — einmal beim Anlegen,
 * einmal auf der Detailseite — waere die Art Abschrift, die spaeter nur an
 * einer der beiden Stellen korrigiert wird.
 */

/** Die Formularwerte fuer einen Datensatz, oder die Vorgaben fuers Anlegen. */
export function toForm(resource) {
    return {
        title: resource?.title || '',
        handle: resource?.handle || '',
        description: resource?.description || '',
        delivery_type: resource?.delivery_type || 'file',
        link_url: resource?.link_url || '',
        requires_confirmation: resource ? !! resource.requires_confirmation : true,
        published: resource ? !! resource.published : true,
        link_ttl: resource?.link_ttl ?? '',
        max_downloads: resource?.max_downloads ?? '',
        grant_ttl_days: resource?.grant_ttl_days ?? '',
        tags: (resource?.tags || []).join(', '),
        marketing_list: resource?.marketing_list || '',
    };
}

/** Leer bleibt leer; 0 bleibt 0. `Number('')` waere 0 und damit ein Limit. */
function number(value) {
    const trimmed = String(value ?? '').trim();
    return trimmed === '' ? null : Number(trimmed);
}

/**
 * Die Gruppenbloecke des Dateiraster-Feldes fuer den Server.
 *
 * Eine Zeile ohne gewaehlte Datei faellt weg: sie ist ein halb ausgefuelltes
 * Raster, keine Datei, und ein Block ohne Dateien faellt mit ihr. Die
 * Reihenfolge ist die des Rasters, per Ziehen geaendert, und genau die liest
 * der Leser spaeter.
 */
export function fileGroups(fileValues) {
    return (fileValues?.groups || [])
        .map((block) => ({
            group: block.group || null,
            files: (block.files || [])
                .map((row) => ({ file: (row.file || [])[0] ?? null, label: row.label || null }))
                .filter((row) => row.file !== null),
        }))
        .filter((block) => block.files.length > 0);
}

/**
 * Was an den Server geht.
 *
 * `handle` nur beim Anlegen: beim Aendern weist die Validierung es zurueck,
 * weil ein neues Handle jeden bereits erteilten Zugang verwaisen liesse.
 */
export function toPayload(form, fileValues, { creating = false } = {}) {
    const isFile = form.delivery_type === 'file';

    return {
        title: form.title,
        ...(creating ? { handle: form.handle || null } : {}),
        description: form.description || null,
        delivery_type: form.delivery_type,
        groups: isFile ? fileGroups(fileValues) : null,
        link_url: isFile ? null : (form.link_url || null),
        requires_confirmation: form.requires_confirmation,
        published: form.published,
        link_ttl: number(form.link_ttl),
        max_downloads: number(form.max_downloads),
        grant_ttl_days: number(form.grant_ttl_days),
        tags: form.tags.split(',').map((tag) => tag.trim()).filter(Boolean),
        marketing_list: form.marketing_list || null,
    };
}

/**
 * Die Schluessel, die ein eigenes Feld auf dem Schirm haben — und damit die,
 * deren Fehlermeldung nicht zusaetzlich in das Banner oben gehoert.
 *
 * Welche das sind, haengt vom Zustand ab: das Handle ist nur beim Anlegen
 * bearbeitbar, und Datei- und Link-Feld tauschen sich mit der Auslieferungsart.
 * Ein abgelehnter Schluessel, dessen Feld gerade nicht sichtbar ist, wuerde
 * sonst nirgends stehen.
 */
export function visibleFieldKeys(form, { creating = false } = {}) {
    const keys = [
        'title', 'description', 'delivery_type', 'requires_confirmation', 'published',
        'link_ttl', 'max_downloads', 'grant_ttl_days', 'tags', 'marketing_list',
    ];

    if (creating) keys.push('handle');
    keys.push(...(form.delivery_type === 'file' ? ['groups', 'files', 'file_asset'] : ['link_url']));

    return keys;
}

/**
 * Ob ein abgelehnter Schluessel ein eigenes Feld auf dem Schirm hat.
 *
 * Die Zeilen des Dateirasters melden `groups.0.files.2.file` und so weiter;
 * sie stehen am Raster selbst, nicht im Banner.
 */
export function hasOwnField(key, visible) {
    return visible.includes(key) || key.startsWith('groups.') || key.startsWith('files.');
}
