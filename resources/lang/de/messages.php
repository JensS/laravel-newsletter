<?php

return [
    'form' => [
        'email' => 'E-Mail-Adresse',
        'name' => 'Name (optional)',
        'submit' => 'Abonnieren',
        'consent' => 'Ich möchte E-Mails von :brand erhalten. Ich kann mich jederzeit über den Link in jeder E-Mail abmelden.',
    ],
    'signup' => [
        'title' => 'Fast geschafft',
        'check_inbox' => 'Falls diese Adresse noch nicht eingetragen ist, haben wir dir eine E-Mail geschickt. Bitte bestätige dein Abonnement über den Link darin.',
        'done_title' => 'Danke',
        'done' => 'Dein Abonnement ist aktiv.',
    ],
    'confirm' => [
        'subject' => 'Bitte bestätige dein Abonnement: :audience',
        'intro' => 'Bitte bestätige, dass du „:audience“ erhalten möchtest.',
        'button' => 'Abonnement bestätigen',
        'ignore' => 'Falls du das nicht angefordert hast, kannst du diese E-Mail ignorieren. Du wirst nicht eingetragen.',
        'page_title' => 'Abonnement bestätigen',
        'page_intro' => 'Bestätige, dass :email „:audience“ erhalten soll.',
        'done_title' => 'Abonnement bestätigt',
        'done' => 'Danke. Du erhältst ab jetzt „:audience“.',
        'expired_title' => 'Link abgelaufen',
        'expired' => 'Dieser Bestätigungslink ist ungültig oder abgelaufen. Bitte melde dich erneut an.',
    ],
    'unsubscribe' => [
        'page_title' => 'Abmelden',
        'page_intro' => '„:audience“ nicht mehr an :email senden?',
        'button' => 'Abmelden',
        'done_title' => 'Du wurdest abgemeldet',
        'done' => 'Du erhältst „:audience“ nicht mehr.',
    ],
    'preferences' => [
        'page_title' => 'E-Mail-Einstellungen',
        'intro' => 'Wähle aus, welche E-Mails :email erhalten soll.',
        'save' => 'Einstellungen speichern',
        'unsubscribe_all' => 'Von allem abmelden',
        'saved' => 'Deine Einstellungen wurden gespeichert.',
        'none' => 'Hier gibt es keine E-Mails, die du verwalten kannst.',
    ],
    'invalid_link' => [
        'title' => 'Ungültiger Link',
        'message' => 'Dieser Link ist ungültig. Bitte verwende den Link aus der neuesten E-Mail.',
    ],
    'footer' => [
        'reason' => 'Du erhältst diese E-Mail, weil du Neuigkeiten von :brand abonniert hast.',
        'unsubscribe' => 'Abmelden',
        'preferences' => 'E-Mail-Einstellungen verwalten',
    ],
];
