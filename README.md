# WP Book a Call

Plugin WordPress con blocco Gutenberg per prenotare **call conoscitive**, in stile TidyCal/Calendly.

## Funzionalità

- Blocco `Book a Call` (dinamico): calendario mensile, slot orari, selettore del fuso orario, form con domande personalizzate.
- Più **tipi di call** (durata, intervallo tra slot, pause prima/dopo, preavviso minimo, orizzonte massimo, luogo).
- Disponibilità con **orari settimanali** (più fasce al giorno) ed **eccezioni** (ferie, festività).
- Email di notifica agli amministratori e conferma al cliente, con **iCal (.ics)** allegato.
- **Annulla e sposta** tramite link firmato nell'email.
- **Google Calendar** opzionale (OAuth2 con client proprio): crea l'evento, genera il link **Google Meet**, esclude gli orari occupati (free/busy).
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
3. Inserisci Client ID e Client secret, salva e premi **Collega account Google**.

Il refresh token è salvato cifrato (libsodium, chiave derivata dai salt di WordPress).

### Cache di pagina

Le risposte REST del plugin inviano `Cache-Control: no-store` e `X-LiteSpeed-Cache-Control: no-cache`. Dopo un aggiornamento del plugin svuota la cache di pagina (il CSS del blocco è incorporato nell'HTML).

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
