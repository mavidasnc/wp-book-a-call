# Changelog

## [0.8.1]

- Correzione: gli eventi Google "tutto il giorno" (compleanni, promemoria) bloccavano l'intera giornata perché il free/busy li considera occupati. Nuova opzione "Ignora gli eventi che durano tutto il giorno" (attiva di default): vengono ignorati anche gli eventi segnati "libero", annullati o gli inviti rifiutati.
- Resend: se la prova o un invio falliscono, l'errore mostra tutti i dati del tentativo (mittente e sua origine, valore salvato nel campo, destinatari, oggetto, chiave mascherata, chiamata e risposta HTTP di Resend, versioni) con il pulsante "Copia". Gli stessi dati restano nell'avviso in admin e nell'email al proprietario. A prova riuscita il messaggio indica mittente e destinatari usati.

## [0.8.0]

- Frontend: i giorni non prenotabili hanno un colore proprio, ambra per i giorni pieni, rosso per le chiusure e viola per le festività (con il nome), più una legenda sotto il calendario. Vale anche per "Sposta" nell'admin. L'endpoint `/slots` restituisce la nuova chiave `closed`.
- Eccezioni: il calendario mostra anche i giorni chiusi solo per altri tipi di call e l'elenco "Giorni bloccati" indica l'ambito di ogni blocco (tutti i tipi di call o uno specifico), con sblocco nell'ambito giusto.
- Notifiche: avviso che Resend non funziona con indirizzi gmail.com (o altri provider gratuiti) e che serve un dominio proprio con i DNS configurati (SPF, DKIM); segnalazione se il mittente attuale usa un dominio gratuito.

## [0.7.1]

- Resend: se la chiave è limitata all'invio (non può annullare i promemoria programmati) i promemoria restano su WP-Cron e l'admin lo segnala, per non mandare email vecchie dopo uno spostamento o un annullamento.

## [0.7.0]

- Test su GitHub: a ogni push e pull request parte il workflow CI (phpcs, PHPStan, PHPUnit, build e controllo dei file compilati); la release dello zip parte solo se i test passano.
- Resend: nella scheda Notifiche si può inserire la chiave API; le email partono da Resend, con una prova all'inserimento. Se Resend dà errore viene disattivato, le email usano WordPress, il proprietario del sito riceve una email e l'admin mostra un avviso con il pulsante "Riprova".
- Promemoria: con Resend attivo vengono programmati (scheduled_at) appena arriva la prenotazione, e aggiornati se la call viene spostata o annullata; WP-Cron resta come rete di sicurezza e la scheda Notifiche mostra l'ultimo controllo e segnala un cron fermo.
- Google Calendar: opzione per impostare un promemoria sul calendario dell'organizzatore.
- Notifiche: messaggi personalizzabili anche per call spostata e annullata, con segnaposto ({name}, {email}, {date}, {time}, ...).
- Privacy: testo standard dell'informativa, mostrato sotto la casella di consenso quando non c'è un URL.
- Prenotazioni (admin): "Sposta" usa lo stesso selettore del sito (due mesi, orari del giorno, conferma).

## [0.6.1]

- Correzione: il widget non mostrava gli orari occupati (lucchetto e giorni pieni) perché ignorava l'elenco ricevuto dal server.

## [0.6.0]

- Frontend: gli orari già occupati restano visibili ma non selezionabili, con un lucchetto; un giorno con tutti gli orari occupati resta cliccabile e li mostra tutti.
- Limiti: nuova opzione per non accettare prenotazioni per il primo giorno operativo successivo a oggi (salta weekend e festività).
- Chiusura automatica nelle festività italiane (Pasqua e Lunedì dell'Angelo incluse), visibili nel calendario delle Eccezioni.
- Prenotazioni (admin): pulsante "Sposta" per cambiare giorno e orario con avviso via email al cliente; l'annullamento ora dice se il cliente è stato avvisato.
- Admin: nuova scheda Notifiche (email e webhook); la scheda Impostazioni contiene limiti, Google Calendar e privacy; tolto il limite di larghezza della pagina.

## [0.5.0]

- Correzione: lo stile dell'admin (card, calendario delle eccezioni, tabelle, spaziature) non veniva caricato; ora il CSS del plugin è agganciato alla pagina.
- Nuovo campo "Email del mittente" (di default l'email di amministrazione del sito); le email del plugin partono con il nome dell'organizzatore invece di "WordPress".
- Il plugin si chiama "Book a call" e nell'elenco dei plugin c'è il link "Impostazioni" che apre direttamente la scheda delle opzioni.
- Interruttori e barra dei filtri delle prenotazioni con più spazio intorno.

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
