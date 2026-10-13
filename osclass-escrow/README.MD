# Elektron Net Osclass, `osclass-escrow` API Specification

- **Version:** 0.1.0 (draft)
- **Date:** October 13, 2026
- **Audience:** Osclass developers, marketplace operators, plugin reviewers
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net); every Osclass hook/helper cited below has been checked against both [`mindstellar/shopclass`](https://github.com/mindstellar/shopclass) and the operator's actual deployment target, `osclass v8.3.1` from [`osclass-classifieds.com`](https://osclass-classifieds.com/download) (see "Status" for the platform differences that matter)
- **See also:** [`../README.MD`](../README.MD) for the escrow model and the timeout policy, [`../doc-elektron/guideline-escrow-psbt-relay.md`](../doc-elektron/guideline-escrow-psbt-relay.md) for the protocol

---

This document specifies how the plugin is wired into Osclass: its hooks, database schema, routes, admin settings and i18n conventions, and the deal workflow. The escrow logic itself (script, address, descriptor, PSBT checks, workflow) lives in `lib/` (namespace `ElektronNet\Escrow`) with its checks in `tests/`.

## Status

Draft, but functionally complete for the core buy -> escrow -> confirm/refund/release flow. Every Osclass integration point (hooks, routes, DAO usage, CSRF, pricing, i18n) is verified directly against source, against both real platforms this plugin targets: [`mindstellar/shopclass`](https://github.com/mindstellar/shopclass) and the actual currently deployed `osclass v8.3.1` from [`osclass-classifieds.com`](https://osclass-classifieds.com/download). Detailed rationale for each platform-specific choice below lives in this repository's commit history, not repeated here.

Not yet verified against a live Elektron Net node: the console commands shown to the parties on the deal page (node wallet) were written from the Bitcoin Core RPC reference and are untested against the fork. The escrow logic in `lib/` is checked by `tests/` and against independent implementations (an independent miniscript compiler for the script, Electrum's PSBT parser, real signatures from a second library).

### Platform notes

- Hook names: `item_detail` (the hook the theme's own template fires, at the correct page position), not `show_item` (fires too early in the request lifecycle to place HTML correctly). `user_menu_filter` is the real mechanism for an account-menu link; `osc_add_route()`'s `$user_menu` flag does not add one by itself.
- CSRF: never assumes a specific token field name (some platforms use a `CSRFName`/`CSRFToken` pair, others a single `octoken`); always calls `osc_csrf_check()` unconditionally once an action's own marker field is present.
- This plugin's three custom-route controllers never call `osc_redirect_to()`: a redirect attempted from that specific rendering path cannot succeed on the real, currently deployed platform (real page markup is already buffered by the time a controller runs), so each renders its result directly in the same response instead (`includes/notice.php`, `includes/order-status.php`).
- Pricing reads `t_item.i_price` (scaled by 1,000,000); the also-present `f_price` column is never populated by real item-submission code.
- Currency eligibility is an admin-configurable list (`currency_codes` preference, default `ELE`/`ELK`), not a single hardcoded code: Osclass stores a currency code as `CHAR(3)`, so a 4-letter code can never be stored there.
- `.mo` loading (all 12 locales) is verified against the real `gettext/gettext` parser both platforms actually use.
- `user_profile_form`/`item_detail` still exist as hooks on the original Osclass lineage but not in Shopclass, which is why the buy widget uses the `item_detail` hook above rather than relying on either.

## Plugin identity

- Deployed as `oc-content/plugins/osclass-escrow/` (this directory, copied or symlinked as-is).
- Entry point: `index.php` (Osclass loads exactly this file per active plugin).
- Translation domain: `osclass-escrow` (the `ELEKTRON_ESCROW_DOMAIN` constant, defined once in `index.php`).
- Admin preference section: `plugin-osclass-escrow`.

## Dependencies (Composer)

See `composer.json`. `vendor/` must be installed (`composer install` inside this directory) before the plugin is activated; `index.php` requires `vendor/autoload.php` unconditionally. Requires PHP 8.0+, matching Shopclass's own `composer.json`.

| Package | Used for |
|---|---|
| `ext-gmp` | Curve check of submitted public keys and base58 decoding in `lib/`. |
| `endroid/qr-code` | Renders QR codes for PSBTs and the payment address on the deal page. |

The escrow logic in `lib/` has no other dependency. Composer's autoloader maps `ElektronNet\\Escrow\\` to `lib/`.

## Building a release zip

This directory as checked into the repository is source, not an installable plugin: it has no `vendor/`, since that would mean committing every dependency's code into version control. Turning it into something that can be extracted straight into `oc-content/plugins/osclass-escrow/` on a real Osclass site means running Composer first. Two ways to get there:

### Automated (GitHub Actions)

Pushing a tag matching `osclass-v*` (e.g. `osclass-v0.1.0`) runs [`.github/workflows/release-osclass-escrow.yml`](../.github/workflows/release-osclass-escrow.yml), which builds the zip via the script below and attaches it to a new GitHub Release. Running that workflow manually (`workflow_dispatch`, with a version string of your choice) instead uploads the zip as a downloadable Actions artifact, useful for trying a build before committing to a real tag.

### Automated (local script)

From the repository root:

```bash
bash scripts/build-osclass-plugin.sh 0.1.0
```

Produces `dist/osclass-escrow-0.1.0.zip`. This is exactly what the GitHub Action runs; read it if you want to see precisely what the manual steps below automate.

### Manual (no script, no CI)

If you would rather not run someone else's shell script, or need to adapt a step for your own setup, here is every step it performs, spelled out:

1. **Copy the plugin directory** into a scratch location (not in place inside your git checkout, so a mistake here cannot dirty your working tree):
   ```bash
   mkdir -p /tmp/elektron-build && cp -a osclass-escrow /tmp/elektron-build/
   cd /tmp/elektron-build/osclass-escrow
   ```
2. **Install dependencies without dev packages:**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. **Recompile every translation.** Skip this step if you have not touched a `.po` file since the `.mo` files in this repository were last committed:
   ```bash
   for po in languages/*/messages.po; do
     msgfmt -o "$(dirname "$po")/messages.mo" "$po"
   done
   ```
   No `msgfmt`? Use the fallback compiler this repository ships instead: `php languages/po2mo.php <locale>/messages.po <locale>/messages.mo`, once per locale.
4. **(Optional) Stamp a version** into the `Version:` line of `index.php`'s plugin header, so the deployed copy reports the version you are shipping:
   ```bash
   sed -i -E "s/^Version: .*/Version: 0.1.0/" index.php
   ```
5. **Remove dev-only files** that a deployed copy does not need (`vendor/` already carries everything required at runtime):
   ```bash
   rm -rf tests && rm -f composer.json composer.lock languages/po2mo.php
   ```
6. **Zip it**, with `osclass-escrow/` itself as the top-level folder inside the archive, so extracting the zip directly into `oc-content/plugins/` produces `oc-content/plugins/osclass-escrow/`:
   ```bash
   cd /tmp/elektron-build && zip -rq osclass-escrow-0.1.0.zip osclass-escrow
   ```

The resulting zip is what you upload through Osclass's own plugin installer, or extract manually into `oc-content/plugins/`.

## Hooks used

| Hook | Where | Purpose |
|---|---|---|
| `osc_register_plugin()` | `index.php` | Activation callback, `elektron_escrow_install()` (creates DB tables, seeds preference defaults). |
| `<plugin_path>_uninstall` | `index.php` | Deactivation callback, `elektron_escrow_uninstall()` (removes preferences, keeps the order table). |
| `<plugin_path>_configure` | `index.php` | "Configure" action in the admin plugin list, renders `admin/settings.php`. |
| `admin_menu_init` | `index.php` | Adds a dedicated "Elektron Net Escrow" entry under Admin > Plugins. |
| `item_detail` | `includes/hooks.php` | Injects the "Buy with Elektron" / "View your order" widget on the item detail page. Receives the current item array. **Not** `show_item`: that hook fires from the item controller's own `doModel()` (data-preparation) method, before the theme emits any page markup at all, so output echoed from it lands ahead of the entire page instead of anywhere inside it; `item_detail` is the hook the theme's own template actually calls at the intended position (confirmed against the real, deployed `osclass-classifieds.com` theme, `oc-content/themes/sigma/item.php`), see "Status". |
| `user_menu_filter` | `includes/hooks.php` | Adds the "My Elektron orders" and "My Elektron wallet" pages to the logged-in user's account menu, the same mechanism Shopclass's own billing feature uses (`hBilling.php`). |
| `cron_minutely` | `includes/hooks.php` | The chain watcher, `elektron_escrow_check_all_deals()`: looks up the funding transaction of every deal that is waiting for it (`awaiting_funding_broadcast`, `confirming`). The deal page runs the same check on load. |
| `cron_daily` | `includes/hooks.php` | Daily sweep that raises `elektron_escrow_buyer_refund_reminder_due` / `elektron_escrow_seller_release_reminder_due` for funded deals whose T1/T2 reminder window (`lib/ReminderScheduler.php`) is open. Delivery is left to whoever listens to these hooks. |

Custom routes (via `osc_add_route()`, registered unconditionally at the top level of `index.php`, matching the pattern Osclass's own bundled `google_maps` plugin uses):

| Route id | URL | Handler | Notes |
|---|---|---|---|
| `elektron_escrow_checkout` | `elektron-escrow/checkout?item=<id>` | `controllers/checkout.php` | GET shows a no-side-effect preview; only a POST with `confirm_checkout=1` and a valid CSRF token creates the deal (status `awaiting_payment`, the escrow is fixed at once from both connected wallets), rendered in the same response. Both parties must have connected a wallet. An existing open deal for the same item and buyer is shown instead of the preview. |
| `elektron_escrow_order_status` | `elektron-escrow/order?order=<id>` | `controllers/order-status.php` | The deal page for either party: next step, escrow address and descriptor, PSBT text/QR/download blocks, history and messages. Every action is a POST with a `deal_action` marker and a CSRF token and goes through `elektron_escrow_deal_perform()`. |
| `elektron_escrow_wallet` | `elektron-escrow/wallet` | `controllers/wallet.php` | Connects a wallet once per account: the user pastes the output of the node console command `listdescriptors` (without `true`). `WalletConnection` extracts the account public key; anything containing a private key is refused before further processing and never stored or shown. A script on the page also blocks the submit. |
| `elektron_escrow_orders` | `elektron-escrow/orders` | `controllers/orders.php` | "My Elektron orders": every deal the user is a party to, with a marker where it is their turn. |

## Database schema

Two tables, entirely owned by this plugin; Osclass's own tables are only ever read. Created by `install.php` (and `elektron_escrow_ensure_schema()` on installations activated earlier), kept on uninstall.

### `elektron_escrow_deal`

| Column | Notes |
|---|---|
| `pk_i_id`, `fk_i_item_id`, `fk_i_buyer_id`, `fk_i_seller_id` | Deal id and the three Osclass references. |
| `amount_lep` | The item's price in lep (1 ELEK = 100,000,000 lep), frozen at checkout. |
| `status` | One of `ElektronNet\\Escrow\\DealStatus`. |
| `buyer_shipping_*`, `buyer_contact_email` | Collected at checkout, shown to the seller once the payment is confirmed. |
| `seller_pubkey`, `seller_exit_pubkey`, `buyer_pubkey`, `buyer_exit_pubkey` | Compressed public keys, hex: a main key for the cooperative path and an exit key for the refund or claim path each. Derived from the parties' connected wallets by `EscrowKeys` at checkout, in the index range `2000000000 + 2 * deal id` (main) and `+ 1` (exit) below the receiving chain of the account key, so they never clash with normal addresses and can be recreated from the seed and the deal number. The plugin never sees a private key. |
| `escrow_address`, `witness_script_hex` | The miniscript escrow built from both keys and both dates (`EscrowScript`). Fixed at checkout. |
| `buyer_refund_locktime`, `seller_release_locktime` | T1 and T2 as unix timestamps, set together with the escrow address and written into its script. |
| `funding_outputs` | JSON list of the confirmed, unspent outputs at the escrow address. The release PSBT must spend exactly these. |
| `seller_payout_address`, `release_psbt_signed` | The payout address (default from the seller's wallet connection, final value from the release submission) and the release PSBT carrying the seller's signature, after it passed the checks. |
| `closing_txid` | Transaction id the party reported when closing the deal. |

### `elektron_escrow_wallet`

One row per user who connected a wallet: `fk_i_user_id`, `s_fingerprint` and `s_origin_path` (as in the pasted descriptor), `s_xpub` (the account public key of the receiving chain), `s_payout_address` (optional). Public data only. The row is kept while the user has open deals.

### `elektron_escrow_deal_event`

Append-only history and chat: one row per workflow action, chain update or message (`s_type`, `s_text`, `fk_i_user_id`, `0` for the chain watcher).

## Escrow eligibility

A listing is only offered escrow if the item's `fk_c_currency_code` (uppercased) is one of the admin-configured `currency_codes` (default `ELE`, `ELK`; Osclass stores currency codes as `CHAR(3)`, so `ELEK` cannot be one) and the item has a numeric price (`elektron_escrow_item_price_elek()` is non-null). The check is made where the "Buy" link is rendered and again in `controllers/checkout.php`. There is no wallet-connection prerequisite: the parties submit keys per deal.

Neither the deal tables nor the checkout flow use Shopclass's native billing/credits system, which is a user buying credits from the operator and has no seller or per-item payment.

## Terminology and number formatting

Three distinct names, used consistently throughout this plugin's strings (see the whitepaper's "The Lydian Elektron" section for where they come from):

- **Elektron Net**: the network/protocol itself. Used for network-level things: wallets, nodes, public keys ("Connect an Elektron Net wallet...").
- **Elektron (ELEK)**: the currency unit. Used wherever an amount or a purchase is meant: "Buy with Elektron (ELEK)", "Elektron order".
- **lepton (lep)**: the subunit, 1 ELEK = 100,000,000 lepton. 

"Elektron Net Escrow" (the plugin's own name, in its admin pages and plugin header) is the one deliberate exception: it names the plugin itself, not a currency amount, the same way "Bitcoin Core" names software rather than an amount of BTC.

Amounts shown to buyers and sellers (currently just the checkout page's payment amount) go through `elektron_escrow_format_amount()` (`includes/formatting.php`), not a raw PHP float. It reads the current visitor's decimal point and thousands separator from Osclass's own per-locale `t_locale` settings (`osc_locale_dec_point()`, `osc_locale_thousands_sep()`), the same mechanism `osc_format_price()` uses for listing prices, so `1234.5` ELEK renders as `1,234.5` for an `en_US` visitor and `1.234,5` for a `de_DE` one. It is a separate function rather than a call to `osc_format_price()` itself: that helper divides by a hardcoded 1,000,000 to match Osclass's own generic `i_price` column, which is the wrong scale for lepton (100,000,000 per ELEK), and it always pads to a fixed decimal count where trimming trailing zeros reads better for a crypto amount.

## Admin settings

Rendered by `views/admin-settings.php`, saved by `admin/settings.php`. Every field maps 1:1 to a `plugin-osclass-escrow` preference (see `install.php` for defaults):

| Field | Preference name | Default |
|---|---|---|
| Network | `network` | `mainnet` |
| Accepted currency codes (one per line) | `currency_codes` | `ELE`, `ELK` |
| Chain-data endpoints (one per line) | `chain_data_endpoints` | `https://mempool.elektron-net.org/api`, `https://mempool2.elektron-net.org/api` |
| Confirmations required | `required_confirmations` | `3` |
| Buyer refund threshold, T1 (days) | `buyer_refund_days` | `30` |
| Seller release threshold, T2 (days) | `seller_release_days` | `60` |

Saving re-validates T1/T2 through `TimeoutPolicy::fromDays()` server-side (never trusts client-side form validation alone) and rejects the save with a flash error if the guardrails from `../README.MD`, "Escrow timeout policy", are violated. A change here only affects orders created afterward; see "Values are frozen per order" in the root README.

Every write here (`install.php`'s defaults, and this save handler) uses `Preference::replace($key, $value, $section, $type)`, not the DAO-inherited `insert()`/`update()`; see "Status" above for why the latter would read back stale within the same request on Shopclass.

## i18n

Translation domain `osclass-escrow`, following Osclass's own gettext convention exactly: `__($text, 'osclass-escrow')` looks `$text` up as a `msgid` against `languages/<locale>/messages.mo`, compiled from the matching `messages.po`. One thing to know when adding a string:

1. A literal, hardcoded string passed straight to `__()`/`_e()` (form labels, error messages) needs its own `msgid`/`msgstr` pair added to **every** `languages/<locale>/messages.po`, then recompiled.

Twelve locales ship today, all fully translated (`en_US` carries identity translations, i.e. the source language itself): `en_US`, `de_DE`, `es_ES`, `pl_PL`, `it_IT`, `tr_TR`, `ru_RU`, `fr_FR`, `pt_BR`, `nl_NL`, `ar_SA`, `zh_CN`. Adding another locale means adding one more `languages/<locale>/messages.po` with the same `msgid`s.

`.po` files are the human-edited source; `.mo` files are what Osclass actually loads at runtime and must be recompiled after editing a `.po`. Prefer `msgfmt -o messages.mo messages.po` (part of standard gettext tooling); `languages/po2mo.php` is a fallback compiler for a machine without `msgfmt` installed (`php po2mo.php <locale>/messages.po <locale>/messages.mo`).

## Deal lifecycle

The site is a relay and a passive display: it never holds a key, builds a transaction, signs, or broadcasts. The logic is `lib/DealWorkflow.php`; `includes/deal.php` persists its results. Every non-terminal status has exactly one party who may act (`DealStatus::actor()`), so neither side can run ahead of the other.

| Status | Acts | Step |
|---|---|---|
| `awaiting_payment` | buyer | Pays the escrow address. The watcher detects it. |
| `confirming` | chain | The watcher waits for the configured confirmations. |
| `funded` | seller | Confirms shipping. The confirmed outputs are stored. |
| `shipped` | buyer | Confirms receipt, refused too close to T1. |
| `awaiting_seller_release` | seller | Builds and signs the release PSBT in their wallet and submits it with the address it pays (prefilled from the wallet connection). |
| `awaiting_buyer_release` | buyer | Checks it in their wallet, signs, finalizes, broadcasts and reports the transaction id. |
| `released`, `refunded`, `claimed`, `cancelled` | | Terminal. Refund (after T1, buyer) and claim (after T2, seller) are available from `confirming` onwards and report the transaction id. |

Cancelling is possible for both parties until a payment is detected on the chain.

A submitted release PSBT is parsed by `PsbtVerifier::assertReleasePsbt()`: it must spend exactly the stored escrow outputs, pay exactly one output to the stated address, carry a plausible fee, have no time lock, and hold only partial signatures of the seller's key with `SIGHASH_ALL`. Signature validity itself is checked by the wallet that finalizes. Everything a party submits is shown as reported; only confirmations come from the chain.

### Emails

`includes/mail.php` sends one email per status change, to whoever acts next (both parties on a finished deal), via `osc_sendMail()`.

## Uninstall behavior

Deactivating the plugin (`elektron_escrow_uninstall()`) deletes its admin preferences only. The deal tables are kept deliberately as transaction history. An operator who wants them gone drops them manually.

## Open Questions

1. **Wallet coverage.** Connecting and signing work with the Elektron Net node wallet only, and the node must support miniscript descriptors. Electrum can pay the escrow address but can neither check nor sign it, and its master public key format (`zpub`, no origin) is not read.
2. **Non-English locale sync.** Strings added with the deal workflow exist only in the source language until the locale files are regenerated.
3. **Fees.** Exit and release fees are chosen by the wallet that builds the transaction.
