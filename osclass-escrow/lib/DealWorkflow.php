<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;
use RuntimeException;

/**
 * The lock-step escrow workflow as pure functions over a deal row: each
 * method checks that the deal is in the expected status, validates the
 * submitted data, and returns the column updates to persist. Nothing here
 * touches a database, the chain, or a wallet. Every transaction is built
 * and signed in a party's own wallet; this class only checks what comes back.
 */
final class DealWorkflow
{
    private const ACTION_ROLES = [
        'seller_submit_key' => 'seller',
        'buyer_submit_key' => 'buyer',
        'seller_mark_shipped' => 'seller',
        'buyer_mark_received' => 'buyer',
        'seller_submit_release' => 'seller',
        'buyer_mark_released' => 'buyer',
        'buyer_mark_refunded' => 'buyer',
        'seller_mark_claimed' => 'seller',
        'cancel' => null,
    ];

    /** @var NetworkParams */
    private $network;

    /** @var TimeoutPolicy */
    private $timeouts;

    public function __construct(NetworkParams $network, TimeoutPolicy $timeouts)
    {
        $this->network = $network;
        $this->timeouts = $timeouts;
    }

    /**
     * Single entry point for user actions: checks that $role may perform
     * $action and dispatches it. $input carries the submitted form fields.
     *
     * @param string $role 'buyer' or 'seller'
     * @param array<string,string> $input
     * @return array<string,mixed> column updates, always including 'status'
     * @throws DealException
     */
    public function perform(string $action, array $deal, string $role, array $input, int $now): array
    {
        if (!array_key_exists($action, self::ACTION_ROLES)) {
            throw new DealException('Unknown action.');
        }
        $requiredRole = self::ACTION_ROLES[$action];
        if ($requiredRole !== null && $requiredRole !== $role) {
            throw new DealException('This step is not available at the moment.');
        }
        $get = function (string $name) use ($input): string {
            return isset($input[$name]) ? trim((string) $input[$name]) : '';
        };

        switch ($action) {
            case 'seller_submit_key':
                return $this->submitKeys($deal, 'seller', $get('pubkey'), $get('exit_pubkey'), $now);
            case 'buyer_submit_key':
                return $this->submitKeys($deal, 'buyer', $get('pubkey'), $get('exit_pubkey'), $now);
            case 'seller_mark_shipped':
                return $this->advance($deal, DealStatus::FUNDED, DealStatus::SHIPPED);
            case 'buyer_mark_received':
                return $this->buyerMarkReceived($deal, $now);
            case 'seller_submit_release':
                return $this->sellerSubmitRelease($deal, $get('psbt'), $get('address'));
            case 'buyer_mark_released':
                $this->expect($deal, DealStatus::AWAITING_BUYER_RELEASE);

                return ['status' => DealStatus::RELEASED, 'closing_txid' => $this->cleanTxid($get('txid'))];
            case 'buyer_mark_refunded':
                $this->expectFundsAtRisk($deal);
                $this->expectTimeReached((int) $deal['buyer_refund_locktime'], $now, 'The refund time has not been reached yet.');

                return ['status' => DealStatus::REFUNDED, 'closing_txid' => $this->cleanTxid($get('txid'))];
            case 'seller_mark_claimed':
                $this->expectFundsAtRisk($deal);
                $this->expectTimeReached((int) $deal['seller_release_locktime'], $now, 'The claim time has not been reached yet.');

                return ['status' => DealStatus::CLAIMED, 'closing_txid' => $this->cleanTxid($get('txid'))];
            default:
                return $this->cancel($deal);
        }
    }

    /**
     * @return array<string,mixed> updates from the chain, empty if nothing changes
     */
    public function advanceFromChain(array $deal, int $receivedLep, int $bestConfirmations, int $requiredConfirmations): array
    {
        if (!in_array($deal['status'], [DealStatus::AWAITING_PAYMENT, DealStatus::CONFIRMING], true)) {
            return [];
        }
        if ($receivedLep < (int) $deal['amount_lep']) {
            return [];
        }
        $status = $bestConfirmations >= $requiredConfirmations ? DealStatus::FUNDED : DealStatus::CONFIRMING;

        return $status === $deal['status'] ? [] : ['status' => $status];
    }

    /**
     * Fields for a new deal. Keys given up front (the seller's from the
     * listing, the buyer's from checkout) are checked and stored; with both
     * parties' keys present the escrow is fixed right away.
     *
     * @return array<string,mixed>
     */
    public function create(?string $sellerKey, ?string $sellerExitKey, ?string $buyerKey, ?string $buyerExitKey, int $now): array
    {
        $deal = ['status' => DealStatus::AWAITING_KEYS];
        foreach (['seller' => [$sellerKey, $sellerExitKey], 'buyer' => [$buyerKey, $buyerExitKey]] as $role => [$main, $exit]) {
            if (($main === null || $main === '') && ($exit === null || $exit === '')) {
                continue;
            }
            $deal = array_merge($deal, $this->cleanKeyPair($role, (string) $main, (string) $exit));
        }

        return $this->fixEscrowIfComplete($deal, $now);
    }

    private function submitKeys(array $deal, string $role, string $mainKey, string $exitKey, int $now): array
    {
        $this->expect($deal, DealStatus::AWAITING_KEYS);
        if (!empty($deal[$role . '_pubkey'])) {
            throw new DealException('This step is not available at the moment.');
        }

        return $this->fixEscrowIfComplete(array_merge($deal, $this->cleanKeyPair($role, $mainKey, $exitKey)), $now);
    }

    /**
     * @return array<string,string>
     */
    private function cleanKeyPair(string $role, string $mainKey, string $exitKey): array
    {
        if ($mainKey === '' || $exitKey === '') {
            throw new DealException('Please give both public keys: the main key and the exit key.');
        }
        $main = $this->cleanPubKey($mainKey);
        $exit = $this->cleanPubKey($exitKey);
        if ($main === $exit) {
            throw new DealException('The main key and the exit key must be different keys.');
        }

        return [$role . '_pubkey' => $main, $role . '_exit_pubkey' => $exit];
    }

    /**
     * With both parties' keys known, fixes the escrow: script, address and
     * both dates are set and never change afterwards.
     *
     * @return array<string,mixed> the key fields plus, when complete, the escrow fields and the next status
     */
    private function fixEscrowIfComplete(array $deal, int $now): array
    {
        $columns = ['seller_pubkey', 'seller_exit_pubkey', 'buyer_pubkey', 'buyer_exit_pubkey'];
        $fields = ['status' => DealStatus::AWAITING_KEYS];
        foreach ($columns as $column) {
            if (!empty($deal[$column])) {
                $fields[$column] = $deal[$column];
            }
        }
        foreach ($columns as $column) {
            if (empty($deal[$column])) {
                return $fields;
            }
        }

        $refundLocktime = $this->timeouts->buyerRefundLocktime($now);
        $claimLocktime = $this->timeouts->sellerReleaseLocktime($now);
        try {
            $witnessScript = EscrowScript::witnessScriptHex(
                $deal['seller_pubkey'],
                $deal['seller_exit_pubkey'],
                $deal['buyer_pubkey'],
                $deal['buyer_exit_pubkey'],
                $refundLocktime,
                $claimLocktime
            );
        } catch (InvalidArgumentException $e) {
            throw new DealException('All four public keys must be different. Buyer and seller must not share a key.');
        }
        $fields['status'] = DealStatus::AWAITING_PAYMENT;

        return $fields + [
            'buyer_refund_locktime' => $refundLocktime,
            'seller_release_locktime' => $claimLocktime,
            'witness_script_hex' => $witnessScript,
            'escrow_address' => EscrowScript::address($witnessScript, $this->network),
        ];
    }

    private function buyerMarkReceived(array $deal, int $now): array
    {
        $this->expect($deal, DealStatus::SHIPPED);
        if (!TimeoutPolicy::canSafelyStartRelease((int) $deal['buyer_refund_locktime'], $now)) {
            throw new DealException('The refund time is too close to start a release now. Use the refund path instead.');
        }

        return ['status' => DealStatus::AWAITING_SELLER_RELEASE];
    }

    /**
     * Takes the release PSBT the seller built and signed in their own wallet.
     */
    private function sellerSubmitRelease(array $deal, string $psbtText, string $payoutAddress): array
    {
        $this->expect($deal, DealStatus::AWAITING_SELLER_RELEASE);
        $payoutAddress = trim($payoutAddress);
        $payoutScript = Address::toScriptPubKeyHex($payoutAddress, $this->network);
        if ($payoutScript === null) {
            throw new DealException('This is not a valid address for this network.');
        }
        $funding = json_decode((string) ($deal['funding_outputs'] ?? ''), true);
        if (!is_array($funding) || $funding === []) {
            throw new DealException('The payment outputs are not known yet. Check the chain first and try again.');
        }

        try {
            $psbt = Psbt::parse($psbtText);
            PsbtVerifier::assertReleasePsbt($psbt, [
                'witnessScriptHex' => $deal['witness_script_hex'],
                'fundingOutputs' => $funding,
                'payoutScriptPubKeyHex' => $payoutScript,
                'allowedPubKeys' => [$deal['seller_pubkey'], $deal['buyer_pubkey']],
                'signerPubKey' => $deal['seller_pubkey'],
            ]);
        } catch (InvalidArgumentException $e) {
            throw new DealException('This is not a valid PSBT.');
        } catch (RuntimeException $e) {
            throw new DealException($e->getMessage());
        }

        return [
            'status' => DealStatus::AWAITING_BUYER_RELEASE,
            'seller_payout_address' => $payoutAddress,
            'release_psbt_signed' => $psbt->toBase64(),
        ];
    }

    private function advance(array $deal, string $from, string $to): array
    {
        $this->expect($deal, $from);

        return ['status' => $to];
    }

    /**
     * Either party may cancel as long as no payment can exist yet.
     */
    private function cancel(array $deal): array
    {
        if (!in_array($deal['status'], DealStatus::CANCELLABLE, true)) {
            throw new DealException('This deal can no longer be cancelled.');
        }

        return ['status' => DealStatus::CANCELLED];
    }

    private function expect(array $deal, string $status): void
    {
        if ($deal['status'] !== $status) {
            throw new DealException('This step is not available at the moment.');
        }
    }

    private function expectFundsAtRisk(array $deal): void
    {
        if (!in_array($deal['status'], DealStatus::FUNDS_AT_RISK, true)) {
            throw new DealException('This step is not available at the moment.');
        }
    }

    private function expectTimeReached(int $locktime, int $now, string $message): void
    {
        if ($locktime <= 0 || $now < $locktime) {
            throw new DealException($message);
        }
    }

    private function cleanPubKey(string $hex): string
    {
        $hex = strtolower(preg_replace('/\s+/', '', $hex));
        if (!PubKey::isValidCompressed($hex)) {
            throw new DealException('This is not a valid compressed public key (66 hex characters, starting with 02 or 03).');
        }

        return $hex;
    }

    private function cleanTxid(string $txid): string
    {
        $txid = strtolower(trim($txid));
        if (!preg_match('/^[0-9a-f]{64}$/', $txid)) {
            throw new DealException('A transaction id is 64 hexadecimal characters.');
        }

        return $txid;
    }
}
