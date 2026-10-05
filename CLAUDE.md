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
npm run release:minor    # release: bump versione, zip, tag, push, GitHub Release (serve CHANGELOG [x.y.z])
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
