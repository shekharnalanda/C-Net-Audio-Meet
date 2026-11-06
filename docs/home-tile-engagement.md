# Homepage tile engagement

The homepage displays the owner's configured **promotional baseline**, automatic daily promotional growth, and additional anonymous visitor interactions. These display totals are not historical analytics measurements. Do not use these numbers in audited traffic, ad billing, enrolment reports, or news analytics. The real `likes`/`views` fields and the promotional display component remain separate in storage.

* Standard starting display: 5,000–8,000 likes, at least 7,000 views. DCA/ADCA: at least 12,000 likes and 16,000 views.
* Start date: 6 November 2026, Asia/Kolkata. Daily steps are seeded per tile, using 25, 40, 50, 80 or 100; view growth is never below like growth. The schedule is computed on demand, with no cron process. Server time is authoritative.
* Real views count once per anonymous browser per tile per Indian calendar date while the tile is visible. A Like is idempotent, can be removed, and is shared across reloads. No comment feature is added.
* Requests are same-origin JSON POSTs with an anonymous HttpOnly cookie, signed per-day token, key validation, input limits, network rate limits and file locking/atomic storage. No raw IP address or visitor cookie is stored in the counter file.
* `tile-engagement.php` and its core are self-contained PHP 8 files; no Laravel/Composer/bootstrap changes or schema migrations are required. Copy the four public assets into the live document root if it differs from this checkout. For Laravel roots, storage is `storage/app/tile-engagement`; otherwise it is a private `.tile-engagement-SITE` directory outside the public document root. Preserve that data across deployment.
* Verify on a temporary test directory: `php scripts/check-tile-engagement.php PUBLIC_ROOT/tile-engagement-core.php`. This does not write student data or live counter data.
* The script selects only homepage content tiles and supports cards dynamically rebuilt by course filters/carousels. Interactive tile links and gallery buttons remain separate from the Like control.
* C-Net News is explicitly excluded and must not receive these files.
