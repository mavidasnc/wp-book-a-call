# Changelog

## [0.4.1]

- Email di conferma: il saluto torna "Ciao {nome}" per non ripetere il ringraziamento due volte.

## [0.4.0]

- Email di conferma al cliente con messaggio di ringraziamento personalizzabile.
- Limiti di prenotazione: massimo di call al giorno (default 2) e una sola call attiva per cliente (si può prenotare di nuovo dopo la call o se la precedente è annullata).
- Export CSV delle prenotazioni dall'admin (punto e virgola, UTF-8, protezione dalle formule).
- Strumenti Privacy di WordPress: esportazione e cancellazione (anonimizzazione) dei dati di una persona, più testo suggerito per la privacy policy.
- Webhook: il payload contiene tutti i dati della prenotazione (link di gestione, pagina di origine, id evento Google, date di creazione e annullamento, descrizione e luogo del tipo di call); in Impostazioni c'è un esempio del JSON.
- Google: guida passo-passo per ottenere Client ID e Client secret e pulsante "Salva e collega con Google"; messaggi chiari se il collegamento fallisce.

## [0.3.0]

- Promemoria email al cliente 24 ore e 1 ora prima della call (WP-Cron ogni 15 minuti), con link per spostare o annullare. Si attivano dalle Impostazioni.
- Webhook verso sistemi esterni (es. n8n) a ogni prenotazione creata, spostata o annullata: POST JSON firmato con HMAC SHA-256, con pulsante di prova.
- Nuova scheda Aggiornamenti: verifica e installa le nuove versioni dalle release GitHub.
- Eccezioni: calendario con lo stesso aspetto del frontend.
- Tipi di call: tabella con durata, disponibilità, luogo, regole e stato senza dover aprire la modifica.
- Spaziature uniformi tra pulsanti, interruttori e campi in tutto l'admin e nella modale.
- Release automatiche: il workflow GitHub Actions costruisce lo zip quando si pubblica un tag; `npm run release:*` non richiede più la build locale (resta disponibile con `--local`).
- Il token dei link di gestione è ora derivato dall'id della prenotazione, così può comparire nei promemoria.

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
