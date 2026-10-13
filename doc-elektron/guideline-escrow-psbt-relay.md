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
         or_i(and_v(v:pk(BUYER),after(T1)),
              and_v(v:pk(SELLER),after(T2)))))
```

- `K1`, `K2` are the two public keys in lexicographic order. `BUYER` and `SELLER` are the same keys by role.
- Both parties together can always spend. After `T1` the buyer alone can spend, after `T2` the seller alone. Both are unix timestamps with `T1 < T2`.
- The dates are counted from the moment both keys are known and are part of the script. They cannot change afterwards, so no pre-signed transactions are needed.
- The plugin validates that each key is a compressed point on secp256k1 (`PubKey`), so a mistyped key cannot produce an unspendable address.
- The witness script compiled by `EscrowScript` was compared byte for byte with an independent miniscript compiler. The descriptor checksum was compared with Electrum's and with the Bitcoin Core documentation vector.

## 3. Workflow

Every non-terminal status has one party who may act (`DealStatus::mayAct()`); `DealWorkflow::perform()` rejects every other action. The platform enforces only what protects against mistakes.

1. Optional, at listing time: the seller stores a public key and a payout address with the listing. They are copied into each new deal.
2. Keys: whoever has no key yet submits one, in any order. The buyer may give theirs at checkout. With both keys the script, address, `T1` and `T2` are fixed.
3. Payment: the buyer pays the escrow address from any wallet. Checking the address in the own wallet is recommended, not enforced. The watcher detects the payment and its confirmations. The deal can be cancelled until a payment is seen.
4. The seller confirms shipping once their own wallet shows the confirmed payment. The buyer confirms receipt, refused close to `T1`.
5. Release: the seller builds the release PSBT in their wallet, paying their own address, signs it and submits it with that address. The buyer checks it, adds the second signature, finalizes, broadcasts and reports the transaction id. The buyer signs last and therefore has the final say.
6. After `T1` the buyer, after `T2` the seller, creates a transaction in their own wallet with the matching lock time and a non-final sequence, signs, finalizes and broadcasts it, and reports the transaction id.

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

1. Key: `getnewaddress`, `getaddressinfo`, submit the public key. Electrum shows the public key under the address details and exports the private key per address; the key then goes into the node wallet's descriptor, Electrum itself cannot sign this escrow.
2. Check: `deriveaddresses "<public descriptor>"` must print the escrow address.
3. Prepare: write the own private key into the descriptor in place of the own public key (every occurrence), add the checksum with `getdescriptorinfo`, import with `importdescriptors`.
4. Release or exit: `walletcreatefundedpsbt` with the escrow outputs as inputs (`add_inputs` false, fee subtracted from the output, lock time and sequence for the exits), `walletprocesspsbt`, then `finalizepsbt` and `sendrawtransaction`.

These commands were written from the Bitcoin Core RPC reference and MUST be verified against a live Elektron Net node before this guideline leaves draft.

## 6. Checklist

- [x] Script, address, descriptor checksum, PSBT parse and release checks (`osclass-escrow/tests/ContractTest.php`).
- [x] Workflow transitions and role enforcement (`osclass-escrow/tests/DealWorkflowTest.php`).
- [x] Script compared with an independent miniscript compiler; real signatures accepted by the checks.
- [ ] End-to-end test with two node wallets on regtest or testnet: release, refund after `T1`, claim after `T2`.
- [ ] Verify the console commands in section 5 against the Elektron Net node.
- [ ] Regenerate locale files for the new strings.

## 7. Open Questions

1. Which wallets beyond the node wallet must be supported, and how do they import the escrow descriptor?
2. Fee handling when fees rise between creating a deal and using an exit; the wallet building the transaction chooses the fee.
