# Changelog

## [0.2.0]

- Widget: due mesi affiancati, orari piccoli su due colonne, layout a tre colonne su schermi larghi (un solo mese su schermi stretti).
- Widget: il clic su un orario apre una modale accessibile con il form e la conferma (anche per lo spostamento).
- Disponibilità: fasce orarie comuni e selezione dei giorni attivi, invece della definizione giorno per giorno.
- Eccezioni: calendario a due mesi per bloccare e sbloccare i giorni con un clic (Maiusc+clic per un intervallo), per tutti i tipi di call o per uno solo.
- Admin: nuovo aspetto con card, più spazio tra gli elementi, badge di stato, duplicazione dei tipi di call.
- Aggiornamenti automatici dalle release GitHub (plugin-update-checker) e script di release.

## [0.1.0]

Prima versione.

- Blocco Gutenberg `wpbac/booking` con calendario, slot, fuso orario e form.
- Tipi di call, orari settimanali, eccezioni.
- Email admin e cliente con iCal allegato; annulla e sposta da link firmato.
- Integrazione opzionale con Google Calendar (evento, Meet, free/busy).
- Anti-spam (honeypot, rate limit, tempo minimo) e consenso privacy.
