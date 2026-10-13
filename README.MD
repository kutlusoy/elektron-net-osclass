# Elektron Net Osclass, Osclass Escrow

- **Version:** 0.3 (draft)
- **Date:** October 13, 2026
- **Audience:** Marketplace operators, buyers and sellers
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net), treat as ground truth for anything this doc references
- **Consumer:** [`osclass-escrow`](./osclass-escrow), the Osclass plugin in this repository
- **See also:** [`osclass-escrow/README.MD`](osclass-escrow/README.MD), [`doc-elektron/guideline-escrow-psbt-relay.md`](doc-elektron/guideline-escrow-psbt-relay.md), [`elektron-net-mempool`](https://github.com/kutlusoy/elektron-net-mempool) and [`elektron-net-electrs`](https://github.com/kutlusoy/elektron-net-electrs) for chain data

---

Trustless escrow payments in Elektron Net (ELEK) for Osclass marketplaces. This repository contains one Osclass plugin. The plugin never holds a key and never builds, signs or broadcasts a transaction. Buyer and seller create and sign every transaction in their own wallet; the marketplace only passes information between them and shows where the deal stands.

## Why escrow instead of a simple payment gateway

On-chain payments are irreversible, so a plain gateway that forwards funds from buyer to seller offers no protection to either side. Here the buyer's payment goes to a 2-of-2 escrow address that only both parties can open together. There is no arbiter, no custodian, and the operator cannot touch the funds.

## How it works

The escrow is a single P2WSH miniscript, so every spending path is enforced on chain and a wallet that understands descriptors can build and sign it on its own:

- both parties together (their main keys) can always spend,
- after the refund date (T1) the buyer alone can reclaim, with the buyer's exit key,
- after the claim date (T2) the seller alone can claim, with the seller's exit key.

Each party therefore contributes two different public keys. A miniscript script may not contain the same key twice, so the exit paths need their own keys. Nobody types keys: both come from the wallet the user connected once.

No pre-signed transactions and no data from the marketplace are needed for the exits. Each party creates the transaction in their own wallet when it is needed.

### Steps

Every step can only be taken by the party it belongs to. The deal page names the next step and explains each term with a "?" helper and a "How do I get this?" block for addresses (node wallet, Electrum, other wallets).

1. **Connect wallet (once per account).** The user pastes the output of the node console command `listdescriptors` (without `true`) and optionally a payout address. Only public data is read; text with a private key is refused. Both parties need this before a deal.
2. **Order placed.** The buyer starts the escrow purchase and enters shipping details. The platform derives both parties' keys for this deal from their connected wallets and fixes the escrow address, its descriptor and both dates.
3. **Payment.** The deal page shows a payment QR code. The buyer scans it with any wallet. The platform watches the block explorer and moves on by itself.
4. **Delivery.** The seller confirms shipping, the buyer confirms receipt. The seller ships only once their own wallet shows the confirmed payment.
5. **Release.** The seller creates the release transaction in their wallet, paying themselves, signs it and pastes it into the deal page, which checks that it spends exactly the escrow payment, pays the stated address and carries only the seller's signature. The buyer checks it in their wallet, adds the second signature, broadcasts it and reports the transaction id.
6. **If something goes wrong.** After T1 the buyer can reclaim the funds alone, after T2 the seller can claim them alone, each with a transaction from their own wallet. The deal page prefills the commands.

A deal can be cancelled by either party until a payment is detected.

The platform enforces only what protects against mistakes: who may act, that connected text holds no private key, that the release PSBT matches the escrow, and that receipt cannot be confirmed too close to the refund date. It does not force confirmations of things it cannot verify, such as having checked an address.

## Trustless by design

- No private key is ever typed into the marketplace. A pasted private key is refused.
- Nobody builds a transaction for you. The marketplace shows what to do and checks what comes back; you create and sign in your own wallet.
- All data on the deal page that was submitted by a party is shown as reported. Only confirmations come from the chain. Every party must verify addresses, amounts and transactions in their own wallet.

## Chain data access

The plugin only reads chain data. It queries an ordered list of Esplora-compatible endpoints (for example [`elektron-net-mempool`](https://github.com/kutlusoy/elektron-net-mempool)) with automatic failover. Running an own instance is recommended for production.

## Escrow timeout policy

### Defaults

- **Buyer refund threshold (T1): 30 days** after the escrow address is fixed.
- **Seller claim threshold (T2): 60 days** after the escrow address is fixed.
- T2 is well past T1 so that the period in which both exits are valid is short.

### Why neither party can set these per order

The refund path only checks elapsed time, not delivery. A short buyer threshold would let a dishonest buyer keep the goods and reclaim the payment. A short seller threshold would let a dishonest seller claim the payment without shipping. Fixed, identical values remove that whole class of manipulation.

### Admin-configurable, not user-configurable

Operators can change T1 and T2 in the plugin's admin settings. Two guardrails apply: T2 MUST be at least 1.5 times T1, and both MUST stay within sane bounds (not below a few days, not above roughly half a year).

### Values are fixed per deal

T1 and T2 are written into the escrow script when its address is fixed and cannot change afterwards. Changing the admin default only affects later deals. Because the dates count from address creation, a payment made long after that leaves little time before T1. The seller is warned when T1 is close and should not ship then.

### Countdown display

The locktimes are timestamps, so the remaining time is exact. The deal page shows both dates, and the daily cron raises reminder hooks ahead of each date.

## Packaging and releases

The plugin ships as a zip that is extracted into `oc-content/plugins/osclass-escrow/`, with its dependencies already in `vendor/`.

- `scripts/build-osclass-plugin.sh [version]` installs the Composer dependencies with `--no-dev`, recompiles every `.mo` from its `.po`, stamps the version into `index.php`, strips dev-only files and writes `dist/osclass-escrow-<version>.zip`.
- `.github/workflows/release-osclass-escrow.yml` runs the tests and the same script in CI. A tag matching `osclass-v*` builds the zip and attaches it to a GitHub Release; a manual run builds a throwaway artifact.

```bash
git tag osclass-v0.1.0
git push origin osclass-v0.1.0

or

git tag -d osclass-v0.1.0 && git push origin :osclass-v0.1.0 && git tag osclass-v0.1.0 && git push origin osclass-v0.1.0
```

## Repository structure

```
elektron-net-osclass/
    osclass-escrow/         The Osclass plugin (lib/ holds the escrow logic, tests/ its checks)
    scripts/                Build script for the plugin zip
    .github/workflows/      Release workflow
    doc-elektron/           Project documentation
```

## Status

Under active development. The escrow script, address, descriptor checksum and PSBT checks are tested, including against independent implementations. The console commands shown to the parties have not been verified against a live Elektron Net node yet.

## License

See the LICENSE file in this repository for full license terms.

## Links

- Website: https://elektron-net.org
- X (Twitter): @elektronnet and @kutlusoy
- Telegram: @elektronnet
