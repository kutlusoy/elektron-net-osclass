# Elektron Net: `elektron-net-osclass` Escrow Protocol Guideline

- **Version:** 0.2 (draft)
- **Date:** October 13, 2026
- **Audience:** Maintainers of `osclass-escrow`; buyers and sellers who sign escrow transactions
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net) (`src/script/miniscript.cpp`, `src/script/descriptor.cpp`, `src/wallet/`), treat as ground truth for anything the doc references
- **Consumer:** [`elektron-net-osclass`](https://github.com/kutlusoy/elektron-net-osclass) (`osclass-escrow/lib/`, `osclass-escrow/tests/`)
- **See also:** [`README.MD`](../README.MD), [`osclass-escrow/README.MD`](../osclass-escrow/README.MD)

---

## 1. Principle

Nobody builds a transaction for you. The plugin MUST NOT hold a private key, build a transaction, sign, or broadcast. Each party creates and signs every transaction in their own wallet. The plugin passes public keys and PSBTs between the parties, shows the next step, and checks what comes back. Each party MUST verify addresses, amounts and transactions in their own wallet.

## 2. Contract

One P2WSH miniscript, implemented by `EscrowScript` and `Descriptor`:

```
wsh(or_d(multi(2,K1,K2),
         or_i(and_v(v:pk(BUYER_EXIT),after(T1)),
              and_v(v:pk(SELLER_EXIT),after(T2)))))
```

- `K1`, `K2` are the two main keys (one per party) in lexicographic order. `BUYER_EXIT` and `SELLER_EXIT` are separate exit keys.
- Each party gives two different public keys. Miniscript sanity rules forbid the same public key twice in one script, so the exit paths cannot reuse the main keys. The node rejects such a script with "contains duplicate public keys".
- Both parties together can always spend. After `T1` the buyer alone can spend with the exit key, after `T2` the seller alone. Both are unix timestamps with `T1 < T2`.
- The dates are counted from the moment both keys are known and are part of the script. They cannot change afterwards, so no pre-signed transactions are needed.
- The plugin validates that each key is a compressed point on secp256k1 (`PubKey`), so a mistyped key cannot produce an unspendable address.
- The witness script compiled by `EscrowScript` was compared byte for byte with an independent miniscript compiler. That compiler does not apply the node's sanity rules, so the descriptor MUST also be accepted by `getdescriptorinfo` and `deriveaddresses` on a node. The descriptor checksum was compared with Electrum's and with the Bitcoin Core documentation vector.

## 3. Workflow

Every non-terminal status has one party who may act (`DealStatus::mayAct()`); `DealWorkflow::perform()` rejects every other action. The platform enforces only what protects against mistakes.

1. Wallet connection, once per account: the user pastes the output of `gethdkeys '{"active_only":true}'` from the node console. The platform reads the master public key and refuses any text containing a private key. For each deal it derives the user's two public keys at `m/1370/(2n)` and `m/1370/(2n+1)` (`n` is the deal number), so they never clash with normal addresses and can be recreated from the seed and the deal number. The path must contain no hardened step: the node compares the keys of a miniscript without private keys and treats keys that need a hardened derivation as duplicates ("contains duplicate public keys"). The master public key reveals the user's addresses and balances to the platform but cannot spend.
2. Checkout: both parties must be connected. The keys are derived, the script, address, `T1` and `T2` are fixed at once and the deal starts at payment.
3. Wallet preparation: each party prepares their wallet for the deal (section 5, step 2) and ticks a confirmation. Shipping, receipt, release and the payment QR code stay locked until then. The refund and claim exits are never locked. The platform cannot verify the preparation, the confirmation is the party's own statement.
4. Payment: the buyer scans the payment QR code with any wallet. The platform checks the block explorer when the page is opened and by cron, and moves on by itself. Preparing the own wallet for signing (below) is recommended before shipping, not enforced. The watcher detects the payment and its confirmations. The deal can be cancelled until a payment is seen.
5. The seller confirms shipping once their own wallet shows the confirmed payment. The buyer confirms receipt, refused close to `T1`.
6. Release: the seller builds the release PSBT in their wallet, paying their own address, signs it and submits it with that address. The buyer checks it, adds the second signature, finalizes, broadcasts and reports the transaction id. The buyer signs last and therefore has the final say.
7. After `T1` the buyer, after `T2` the seller, creates a transaction in their own wallet with the matching lock time and a non-final sequence, signs, finalizes and broadcasts it, and reports the transaction id.

A key swapped by the platform cannot cost the buyer money: the seller's wallet would not see the payment and the seller would not ship, and the buyer reclaims after `T1`.

## 4. Release PSBT checks

`PsbtVerifier::assertReleasePsbt()` MUST accept a submitted release PSBT only if:

- the transaction spends exactly the confirmed unspent outputs at the escrow address, nothing else,
- it has exactly one output, paying the stated address, and its lock time is not a timestamp,
- the fee is not negative and at most 5 percent of the input value,
- every input carries a witness UTXO or a matching previous transaction for the escrow output, and its witness script, if present, equals the deal's script,
- the only other fields are partial signatures, `SIGHASH_ALL` sighash type and BIP32 derivations,
- every partial signature is DER-shaped, ends in `SIGHASH_ALL`, and the signing keys are exactly the seller's key on every input.

ECDSA validity is not verified by the plugin; the wallet that finalizes does that. Real signatures from a second library were accepted by these checks.

## 5. Wallet steps (node wallet)

The wallet must support miniscript descriptors. The deal page shows the commands with the deal's values filled in.

1. Connect: `gethdkeys '{"active_only":true}'` once, paste the result (section 3, step 1).
2. Prepare, per deal, before signing: `gethdkeys '{"active_only":true,"private":true}'` locally gives the wallet key (`xprv...`, never shown to anyone, never pasted into the platform). The deal page shows one descriptor with `<XPRV>/1370/N` in place of the user's two keys and the other party's keys as public keys. After replacing `<XPRV>`, `getdescriptorinfo` adds the checksum and `importdescriptors` imports it (timestamp 0 if the payment is already confirmed). `getaddressinfo` on the escrow address must report `ismine` and `solvable` true and the address must equal the one shown, otherwise `walletcreatefundedpsbt` stops with "Not solvable pre-selected input". This is also the check that the platform did not swap a key.
3. Electrum cannot check or sign this escrow (it only signs for addresses it owns) and is not modified. It can pay the escrow address by QR code.
4. Alternatively the deal page builds an unsigned PSBT on request (`PsbtBuilder`: spends exactly the confirmed escrow outputs to an address and fee rate the party gives; nothing is stored) that the party loads into the wallet window and signs. The wallet shows the transaction before signing.
5. Release or exit: `walletcreatefundedpsbt` with the escrow outputs as inputs (`add_inputs` false, fee subtracted from the output, an explicit `fee_rate` because a node without fee estimates refuses to guess, lock time and sequence for the exits), `walletprocesspsbt`, then `finalizepsbt` and `sendrawtransaction`.

These commands were written from the Bitcoin Core RPC reference and MUST be verified against a live Elektron Net node before this guideline leaves draft.

## 6. Checklist

- [x] Script, address, descriptor checksum, PSBT parse and release checks (`osclass-escrow/tests/ContractTest.php`).
- [x] Workflow transitions and role enforcement (`osclass-escrow/tests/DealWorkflowTest.php`).
- [x] Script compared with an independent miniscript compiler; real signatures accepted by the checks.
- [ ] End-to-end test with two node wallets on regtest or testnet: release, refund after `T1`, claim after `T2`.
- [ ] Verify the console commands in section 5 against the Elektron Net node, starting with `getdescriptorinfo` (must report `issane` true) and `deriveaddresses` for a deal's public descriptor.
- [ ] Regenerate locale files for the new strings.

## 7. Open Questions

1. Which wallets beyond the node wallet must be supported, and how do they import the escrow descriptor? The Electrum master public key (`zpub`) is not read yet.
2. Fee handling when fees rise between creating a deal and using an exit; the wallet building the transaction chooses the fee.
