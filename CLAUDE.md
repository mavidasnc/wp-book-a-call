# WP Book a Call

Plugin WordPress (PHP 8.3, WPCS, namespace `Mavida\BookACall`) con blocco Gutenberg per prenotare call conoscitive. Commenti in italiano.

## Comandi

```bash
composer install && npm install --ignore-scripts   # su Windows gli script di fs-ext-extra-prebuilt falliscono
npm run build            # build blocchi + admin in build/
npm run build:zip        # build + composer --no-dev + zip in zip/ + ripristino dev
composer lint            # phpcs (deve restare a zero errori e warning)
composer analyze         # phpstan livello 6
composer test            # phpunit (tests/Unit)
npm run release:minor    # bump versione, commit, tag, push: la release la costruisce il workflow GitHub Actions (--local per farla da qui)
```

Il lint JS (`wp-scripts lint-js`) non parte in questo ambiente (problema di tooling), mentre la build sì.

## Struttura

- `src/Availability/SlotGenerator.php`, `src/Calendar/IcsBuilder.php`: logica pura, testata.
- `src/Booking/BookingService.php`: crea, sposta e annulla (lock DB, Google, email).
- `src/REST/`: `PublicController` (senza nonce, protetto da honeypot, rate limit e token) e `AdminController` (`manage_options`).
- `src/Google/`: OAuth2 e Calendar API via `wp_remote_*`. Segreti cifrati con `Support/Crypto`.
- `blocks/booking/`: blocco dinamico (`render.php` passa la config al widget React in `view.js`).
- `assets/admin/`: app React dell'admin.

## Punti da ricordare

- Gli orari in DB sono timestamp UNIX UTC; gli orari settimanali sono nel fuso `wp_timezone()`.
- Ogni prenotazione occupa anche le pause del proprio tipo di call (`BookingRepository::busy_intervals`).
- Le risposte REST del plugin devono restare fuori da qualunque cache (LiteSpeed): vedi `RestController::no_cache`.
- Il CSS del blocco è incorporato nell'HTML: dopo un deploy va purgata la cache di pagina.
- `block.json` non ha `version` di proposito: WordPress usa la data del file e il CSS si aggiorna a ogni build.
- Con WP-CLI sul blog di produzione serve `--url=https://maurizio.mavida.com` (il `siteurl` letto da CLI è `https:///app`).
- Gli aggiornamenti automatici passano da `Support/Updater.php` (plugin-update-checker + release asset): il repo GitHub `mavidasnc/wp-book-a-call` deve restare pubblico.
- Disponibilità: il backend salva una mappa per giorno, l'admin la edita come "fasce comuni + giorni attivi" (`assets/admin/availability.js`).
- Eccezioni: `ExceptionRepository::toggle()` riscrive gli intervalli di un ambito usando `Availability/DateRanges` (unione e divisione, testata).
- Promemoria: `Reminders/ReminderService` (cron `wpbac_send_reminders` ogni 15 min); i flag `reminder_24_sent`/`reminder_1_sent` evitano invii doppi o promemoria subito dopo la conferma. Il token di gestione è `Token::for_booking($id)` (derivato, ricalcolabile).
- Webhook: `Webhook/WebhookSender` agganciato alle azioni `wpbac_booking_*`, non bloccante, firma HMAC in `X-Wpbac-Signature`.
- Aggiornamenti dall'admin: `REST/UpdateController` + `Support/Updater` (PUC); l'installazione usa `Plugin_Upgrader`.
- Verifica visiva dell'admin senza login: harness locale con gli script core scaricati da `load-scripts.php` e le API simulate (vedi la cartella di lavoro della sessione, non è nel repo).
- Limiti: `max_per_day` entra in `SlotGenerator::generate` (parametri finali opzionali, conteggio per giorno calcolato da `AvailabilityService`); `one_active_per_client` è un controllo in `BookingService::create`, dentro il lock.
- Privacy: `Privacy/PrivacyHandler` (exporter/eraser per email). L'eraser rilegge sempre la pagina 1 perché le righe anonimizzate non corrispondono più all'email.
- Export CSV: `GET /admin/bookings/export` restituisce `{filename, csv}`; il download lo crea il browser (Blob). `Export/CsvBuilder` è pura e testata.
- OAuth Google: il callback `admin-post.php?action=wpbac_google_callback` rimanda all'admin con `wpbac_google=ok|error&wpbac_reason=<codice>`; serve un client OAuth di tipo "Applicazione web" (un client "Desktop" accetta solo redirect su localhost).
- Orari occupati: `SlotGenerator::generate()` raccoglie in `$taken` (parametro per riferimento) gli slot previsti ma bloccati; `AvailabilityService::slots_with_taken()` li espone e l'endpoint pubblico `/slots` risponde con `slots` e `taken`.
- Giorni chiusi: `AvailabilityService::closed_days()` unisce eccezioni, `Availability/ItalianHolidays` (se `close_holidays`) e `Availability/NextOperativeDay` (se `skip_next_day`).
- Spostamento dall'admin: `POST /admin/bookings/{id}/reschedule` usa `BookingService::reschedule()`, che manda le email e aggiorna Google; `GET /admin/bookings/{id}/slots` esclude la prenotazione stessa.
- Email: `Email/EmailSender` prepara il messaggio (`prepare`) e lo consegna con `deliver()`: `Email/ResendClient` se `Email/ResendStatus::is_active()`, altrimenti (o se Resend fallisce, che lo disattiva e lancia `wpbac_resend_disabled`) `wp_mail`. Segnaposto dei messaggi in `Support/Placeholders`.
- Promemoria con Resend: `Reminders/ReminderScheduler` programma con `scheduled_at` (stato 2 in `reminder_*_sent`, id in `reminder_*_ref`, schema v3); `ReminderSchedule::plan()` è pura. Il cron `ReminderService::run()` sincronizza i promemoria oltre l'orizzonte di 30 giorni e scrive `wpbac_reminders_last_run`.
- Admin "Sposta": `RescheduleModal` riusa `blocks/booking/Picker` (props `loadSlots`, `selected`); gli stili del widget entrano nel bundle admin via `assets/admin/styles/widget.scss`.
- CI: `.github/workflows/ci.yml` (riusato da `release.yml`); `scripts/check-build.js` verifica i file compilati e i percorsi `build/...` citati nel PHP.
