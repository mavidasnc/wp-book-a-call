# WP Book a Call

Plugin WordPress con blocco Gutenberg per prenotare **call conoscitive**, in stile TidyCal/Calendly.

## Funzionalità

- Blocco `Book a Call` (dinamico): calendario mensile, slot orari, selettore del fuso orario, form con domande personalizzate.
- Più **tipi di call** (durata, intervallo tra slot, pause prima/dopo, preavviso minimo, orizzonte massimo, luogo).
- Disponibilità con **orari settimanali** (più fasce al giorno) ed **eccezioni** (ferie, festività).
- Email di notifica agli amministratori e conferma al cliente, con **iCal (.ics)** allegato.
- **Annulla e sposta** tramite link firmato nell'email.
- **Google Calendar** opzionale (OAuth2 con client proprio): crea l'evento, genera il link **Google Meet**, esclude gli orari occupati (free/busy).
- Messaggio di ringraziamento personalizzabile nell'email di conferma.
- Limiti: massimo di call al giorno e una sola call attiva per cliente.
- Export CSV delle prenotazioni e strumenti Privacy di WordPress (esporta e anonimizza i dati di una persona).
- Promemoria email 24 ore e 1 ora prima della call (WP-Cron).
- Webhook firmato (HMAC SHA-256) verso n8n o altri sistemi a ogni prenotazione.
- Anti-spam: honeypot, tempo minimo di compilazione, rate limit, consenso privacy obbligatorio.
- Nessuna doppia prenotazione: lock su database e nuovo controllo di disponibilità prima del salvataggio.

## Requisiti

PHP 8.3+, WordPress 6.5+, estensione `sodium` (inclusa in PHP 8.3).

## Installazione

1. Genera lo zip: `npm install && composer install && npm run build:zip` (produce `zip/wp-book-a-call-<versione>.zip`).
2. Installa lo zip da Plugin > Aggiungi nuovo > Carica plugin e attivalo.
3. Dall'attivazione trovi un tipo di call di esempio ("Call conoscitiva", 30 minuti, lun-ven).
4. Crea una pagina e inserisci il blocco **Book a Call**.

## Configurazione

Menu **Book a Call** nell'admin:

| Scheda | Cosa fa |
| --- | --- |
| Prenotazioni | Elenco, dettagli e annullamento |
| Tipi di call | Durata, orari settimanali, domande al cliente |
| Eccezioni | Giorni di chiusura, globali o per tipo di call |
| Impostazioni | Destinatari email, privacy, Google Calendar |

### Google Calendar

1. In Google Cloud Console crea un client OAuth (tipo "Applicazione web") e abilita la Google Calendar API.
2. Aggiungi come URI di reindirizzamento quello mostrato in **Impostazioni > Google Calendar**.
3. Inserisci Client ID e Client secret e premi **Salva e collega con Google**. L'admin contiene una guida passo-passo (usa un client OAuth di tipo "Applicazione web" e pubblica l'app, altrimenti in modalità test il collegamento scade dopo 7 giorni).

Il refresh token è salvato cifrato (libsodium, chiave derivata dai salt di WordPress).

### Festività e giorno successivo

In Impostazioni > Limiti e chiusure: **chiusura automatica nelle festività italiane** (attiva di default) e, a scelta, **nessuna prenotazione per il primo giorno operativo successivo a oggi** (weekend e festività non contano). Gli altri giorni di chiusura si bloccano dalla scheda Eccezioni.

### Mittente delle email

In Impostazioni > Notifiche si può indicare l'**email del mittente**; se è vuota si usa l'email di amministrazione del sito. Il nome del mittente è quello dell'organizzatore. Le altre email di WordPress non sono toccate. Perché le email non finiscano in spam, l'indirizzo deve appartenere a un dominio abilitato a spedire dal server (SPF/DKIM).

### Limiti di prenotazione

In Impostazioni: **massimo di call al giorno** (default 2, 0 = nessun limite, vale per tutti i tipi di call e usa il fuso del sito) e **una sola call prenotata per cliente** (stessa email, maiuscole ignorate). Il cliente può prenotare di nuovo quando la call è conclusa o se l'ha annullata.

### Privacy

Il plugin si integra con Strumenti > Esporta dati personali e Cancella dati personali. La cancellazione **anonimizza** le prenotazioni dell'email indicata (nome, email, risposte, IP, link) lasciando data e ora; le call future vengono annullate senza email e gli eventi su Google Calendar eliminati. Se è attivo un webhook, l'annullamento delle call future genera comunque l'evento `booking.cancelled`: i sistemi collegati devono occuparsi dei propri dati.

### Cache di pagina

Le risposte REST del plugin inviano `Cache-Control: no-store` e `X-LiteSpeed-Cache-Control: no-cache`. Dopo un aggiornamento del plugin svuota la cache di pagina (il CSS del blocco è incorporato nell'HTML).

## Aggiornamenti e release

Il plugin si aggiorna da solo dalle **release di GitHub** (libreria `plugin-update-checker`, come gli altri plugin Mavida): WordPress mostra la notifica nella pagina Plugin e installa lo zip allegato alla release.

Dall'admin, scheda **Aggiornamenti**, si può verificare subito la presenza di una nuova versione e installarla con un clic.

Per pubblicare una nuova versione, con il working tree pulito:

```bash
# aggiorna prima CHANGELOG.md con la sezione ## [x.y.z]
npm run release:patch   # oppure release:minor / release:major
```

Lo script aggiorna la versione (header del plugin, `WPBAC_VERSION`, `package.json`, `phpstan-constants.php`), crea commit e tag e fa push. Il workflow `.github/workflows/release.yml` costruisce lo zip e pubblica la release; lo script attende che sia pronta. Richiede `git` e `gh` autenticato. Con `--local` (`node scripts/release.js minor --local`) lo zip si costruisce sul proprio computer (servono Node, npm e Composer).

## Promemoria e webhook

- **Promemoria:** in Impostazioni si attivano quelli a 24 ore e a 1 ora. Partono da WP-Cron (ogni 15 minuti) solo per le call prenotate con anticipo sufficiente.
- **Webhook:** in Impostazioni si indica l'URL (es. un nodo Webhook di n8n) e un segreto facoltativo. Eventi: `booking.created`, `booking.rescheduled`, `booking.cancelled`. Il corpo è JSON; se c'è un segreto la richiesta ha l'header `X-Wpbac-Signature: sha256=<hmac del corpo>`. L'invio non blocca la prenotazione.

## Hook per sviluppatori

- `wpbac_booking_created( $booking, $event_type )`
- `wpbac_booking_rescheduled( $booking, $event_type )`
- `wpbac_booking_cancelled( $booking, $event_type )`

I template email si possono sovrascrivere nel tema in `wp-book-a-call/email/default-html.php` e `default-text.php`.

## Sviluppo

```bash
composer install && npm install
npm run start        # watch
npm run build
composer lint        # WPCS
composer analyze     # PHPStan livello 6
composer test        # PHPUnit
```

## Architettura

Namespace `Mavida\BookACall`, PSR-4 su `src/`.

- `Availability/SlotGenerator` e `Calendar/IcsBuilder`: logica pura, coperta da test.
- `Availability/AvailabilityService`: unisce database, eccezioni e free/busy Google.
- `Booking/BookingService`: crea, sposta e annulla (lock, Google, email).
- `REST/PublicController` (slot, prenotazioni, gestione) e `REST/AdminController` (area riservata a `manage_options`).
- `blocks/booking`: blocco e widget React; `assets/admin`: app React dell'admin.

Gli endpoint pubblici non usano il nonce `wp_rest` (le pagine possono essere in cache): la protezione è affidata a honeypot, rate limit, validazione e al token dei link di gestione (salvato come hash HMAC).
