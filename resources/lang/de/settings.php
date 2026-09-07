<?php

/*
 * Die Einstellungsseite der Suite, Abschnitt Lead Magnets.
 *
 * Schluesselgleich mit resources/lang/en/settings.php. Die Feldschluessel sind
 * der Config-Pfad mit ersetzten Punkten (`delivery.link_ttl` →
 * `delivery_link_ttl`), weil ein Punkt im Sprachschluessel fuer den
 * Uebersetzer ein Pfadtrenner ist.
 *
 * Die Beschreibungen sagen, was passiert, wenn man den Wert aendert — nicht,
 * wie das Feld heisst.
 */

return [

    'groups' => [

        'delivery' => [
            'title' => 'Auslieferung',
            'description' => 'Die Vorgaben für jede Ressource, die nichts Eigenes gesetzt hat. Der Speicherort der Dateien steht weiterhin in der Config, weil ein Wechsel die Dateien zuerst umziehen müsste.',
        ],

        'requests' => [
            'title' => 'Anfragen',
            'description' => 'Was zwischen dem Absenden des Formulars und der Bestätigung gilt. Das Anfrage-Limit und der Routen-Präfix bleiben in der Config: beide werden beim Registrieren der Routen gelesen und würden erst beim nächsten Deploy wirken.',
        ],

        'mail' => [
            'title' => 'Mails',
            'description' => 'Welche Vorlagen aus dem E-Mail-Vorlagen-Addon genommen werden. Ohne dieses Addon gelten die mitgelieferten Blade-Ansichten und diese Werte bleiben ohne Wirkung.',
        ],

        'integrations' => [
            'title' => 'Geschwister-Addons',
            'description' => 'Jeder Schalter gilt für ein Addon, das installiert ist und trotzdem nicht beschrieben werden soll. Ein nicht installiertes Addon ist ohnehin aus.',
        ],

    ],

    'fields' => [

        'delivery_link_ttl' => [
            'label' => 'Gültigkeit des Download-Links (Minuten)',
            'description' => 'Wie lange ein verschickter Link funktioniert. Kürzer heißt: wer die Mail später öffnet, muss neu anfragen. Bereits verschickte Links behalten ihre alte Frist.',
        ],

        'delivery_max_downloads' => [
            'label' => 'Maximale Downloads je Zugang',
            'description' => 'Wie oft eine Freigabe eingelöst werden darf. Leer heißt: kein Deckel, dann begrenzt nur die Gültigkeit des Links.',
        ],

        'delivery_grant_ttl_days' => [
            'label' => 'Verfall einer nicht eingelösten Freigabe (Tage)',
            'description' => 'Nach so vielen Tagen läuft ein Zugang ab, der nie benutzt wurde. Leer heißt: er bleibt bestehen.',
        ],

        'requests_confirmation_ttl_hours' => [
            'label' => 'Bestätigungsfenster (Stunden)',
            'description' => 'Wie lange der Bestätigungslink aus der Mail gilt. Danach ist er tot und der Besucher fragt erneut an.',
        ],

        'requests_honeypot' => [
            'label' => 'Name des Fallenfelds',
            'description' => 'Das Feld, das ein Mensch nie ausfüllt und ein Bot immer. Wird es geändert, muss das eigene Anfrage-Formular denselben Namen tragen, sonst gilt jede echte Anfrage als Bot.',
        ],

        'mail_confirmation_template' => [
            'label' => 'Vorlage für die Bestätigungsmail',
            'description' => 'Der Slug der Vorlage im E-Mail-Vorlagen-Addon. Findet sich keine Vorlage unter diesem Slug, geht die mitgelieferte Ansicht raus.',
        ],

        'mail_delivery_template' => [
            'label' => 'Vorlage für die Auslieferungsmail',
            'description' => 'Der Slug der Vorlage, die den Download-Link trägt. Findet sich keine Vorlage unter diesem Slug, geht die mitgelieferte Ansicht raus.',
        ],

        'integrations_leadhub' => [
            'label' => 'LeadHub beschreiben',
            'description' => 'Aus: eine bestätigte Anfrage legt keinen Kontakt mehr an und die Tags der Ressource werden nirgends geschrieben.',
        ],

        'integrations_marketing' => [
            'label' => 'Marketing beschreiben',
            'description' => 'Aus: die bestätigte Adresse wird in keinen Verteiler mehr eingetragen, auch wenn eine Ressource einen nennt.',
        ],

        'integrations_email_templates' => [
            'label' => 'E-Mail-Vorlagen verwenden',
            'description' => 'Aus: Bestätigung und Auslieferung gehen mit den mitgelieferten Blade-Ansichten raus statt mit den im Control Panel gepflegten Vorlagen.',
        ],

        'integrations_suppression' => [
            'label' => 'Sperrliste beachten',
            'description' => 'Aus: an Adressen, die abgeprallt oder als Beschwerde gemeldet sind, wird wieder zugestellt.',
        ],

        'integrations_activity' => [
            'label' => 'Aktivität aufzeichnen',
            'description' => 'Aus: Anfrage, Bestätigung, Auslieferung und Download landen nicht mehr auf der gemeinsamen Zeitachse.',
        ],

        'integrations_insights' => [
            'label' => 'Kennzahlen anbieten',
            'description' => 'Aus: die vier Kacheln dieses Addons stehen auf dem Auswertungs-Dashboard nicht zur Auswahl. Das ist etwas anderes, als eine Null anzuzeigen.',
        ],

    ],

];
