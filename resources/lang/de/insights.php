<?php

/**
 * Die Beschriftungen der Kennzahlen, die dieses Addon an `statamic-insights`
 * meldet.
 */
return [

    'metric_group' => 'Lead-Magnets',

    'metric_requested' => 'Angefragt',
    'metric_requested_description' => 'Freebies, die im Zeitraum zuletzt angefragt wurden. Eine zweite Anfrage derselben Adresse für dieselbe Ressource ersetzt die erste; es gibt pro Adresse und Ressource nur eine Zeile.',

    'metric_confirmed' => 'Bestätigt',
    'metric_confirmed_description' => 'Bestätigungen im Zeitraum, gezählt an dem Moment, in dem der Zugang aufging. Nicht jede Bestätigung gehört zu einer Anfrage desselben Zeitraums.',

    'metric_downloads' => 'Downloads',
    'metric_downloads_description' => 'Ausgelieferte Dateien im Zeitraum. Wer zweimal herunterlädt, zählt zweimal.',

    'metric_confirm_rate' => 'Bestätigungsquote',
    'metric_confirm_rate_description' => 'Bestätigungen im Verhältnis zu den Anfragen des Zeitraums. Ohne Anfragen gibt es keinen Wert.',

    'metric_breakdown_resource' => 'Ressource',

    'metric_no_resource' => 'Ohne Ressource',

];
