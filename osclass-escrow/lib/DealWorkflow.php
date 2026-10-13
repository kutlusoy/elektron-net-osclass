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
        'seller_mark_shipped' => 'seller',
        'buyer_mark_received' => 'buyer',
        'seller_submit_release' => 'seller',
        'buyer_mark_released' => 'buyer',
        'buyer_mark_refunded' => 'buyer',
        'seller_mark_claimed' => 'seller',
        'confirm_prepared' => null,
        'open_dispute' => null,
        'arbiter_rule' => 'arbiter',
        'winner_submit_psbt' => null,
        'arbiter_complete' => 'arbiter',
        'cancel' => null,
    ];

    /** Actions that need the party to have confirmed that their wallet is prepared. */
    private const NEEDS_PREPARED_WALLET = ['seller_mark_shipped', 'buyer_mark_received', 'seller_submit_release', 'buyer_mark_released', 'winner_submit_psbt'];

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
        if (in_array($action, self::NEEDS_PREPARED_WALLET, true) && empty($deal[$role . '_prepared_at'])) {
            throw new DealException('Confirm first that you prepared your wallet for this deal.');
        }
        $get = function (string $name) use ($input): string {
            return isset($input[$name]) ? trim((string) $input[$name]) : '';
        };

        switch ($action) {
            case 'confirm_prepared':
                if (DealStatus::isTerminal($deal['status']) || $get('confirmed') !== '1') {
                    throw new DealException('Tick the box to confirm that you prepared your wallet.');
                }

                return [$role . '_prepared_at' => date('Y-m-d H:i:s', $now)];
            case 'open_dispute':
                return $this->openDispute($deal, $role, $get('reason'), $now);
            case 'arbiter_rule':
                return $this->arbiterRule($deal, $get('ruling'), $get('note'));
            case 'winner_submit_psbt':
                return $this->winnerSubmitPsbt($deal, $role, $get('psbt'), $get('address'));
            case 'arbiter_complete':
                $this->expect($deal, DealStatus::AWAITING_ARBITER_SIGNATURE);

                return [
                    'status' => ($deal['ruling'] ?? '') === 'seller' ? DealStatus::RELEASED : DealStatus::REFUNDED,
                    'closing_txid' => $this->cleanTxid($get('txid')),
                ];
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
     * Fields for a new deal from the four public keys the two connected
     * wallets provide. The escrow is fixed right away: script, address and
     * both dates are set and never change afterwards.
     *
     * @param array{main:string,exit:string} $sellerKeys
     * @param array{main:string,exit:string} $buyerKeys
     * @param string|null $arbiterKey public key of the arbiter for this deal, if the deal has one
     * @return array<string,mixed>
     */
    public function create(array $sellerKeys, array $buyerKeys, int $now, ?string $arbiterKey = null): array
    {
        $seller = $this->cleanKeyPair('seller', $sellerKeys['main'], $sellerKeys['exit']);
        $buyer = $this->cleanKeyPair('buyer', $buyerKeys['main'], $buyerKeys['exit']);
        if ($arbiterKey !== null) {
            $arbiterKey = $this->cleanPubKey($arbiterKey);
        }
        $refundLocktime = $this->timeouts->buyerRefundLocktime($now);
        $claimLocktime = $this->timeouts->sellerReleaseLocktime($now);
        try {
            $witnessScript = EscrowScript::witnessScriptHex(
                $seller['seller_pubkey'],
                $seller['seller_exit_pubkey'],
                $buyer['buyer_pubkey'],
                $buyer['buyer_exit_pubkey'],
                $refundLocktime,
                $claimLocktime,
                $arbiterKey
            );
        } catch (InvalidArgumentException $e) {
            throw new DealException('All public keys must be different. Buyer, seller and arbiter must not share a wallet.');
        }

        return $seller + $buyer + [
            'arbiter_pubkey' => $arbiterKey,
            'status' => DealStatus::AWAITING_PAYMENT,
            'buyer_refund_locktime' => $refundLocktime,
            'seller_release_locktime' => $claimLocktime,
            'witness_script_hex' => $witnessScript,
            'escrow_address' => EscrowScript::address($witnessScript, $this->network),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function cleanKeyPair(string $role, string $mainKey, string $exitKey): array
    {
        $main = $this->cleanPubKey($mainKey);
        $exit = $this->cleanPubKey($exitKey);
        if ($main === $exit) {
            throw new DealException('The main key and the exit key must be different keys.');
        }

        return [$role . '_pubkey' => $main, $role . '_exit_pubkey' => $exit];
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
        [$address, $psbt] = $this->verifiedRelease($deal, 'seller', $psbtText, $payoutAddress);

        return [
            'status' => DealStatus::AWAITING_BUYER_RELEASE,
            'seller_payout_address' => $address,
            'release_psbt_signed' => $psbt->toBase64(),
        ];
    }

    /**
     * Takes the transaction the party in the right built and signed after the arbiter's ruling.
     */
    private function winnerSubmitPsbt(array $deal, string $role, string $psbtText, string $address): array
    {
        $this->expect($deal, DealStatus::RULED);
        if (($deal['ruling'] ?? null) !== $role) {
            throw new DealException('This step is not available at the moment.');
        }
        [$address, $psbt] = $this->verifiedRelease($deal, $role, $psbtText, $address);

        return [
            'status' => DealStatus::AWAITING_ARBITER_SIGNATURE,
            'ruling_address' => $address,
            'ruling_psbt' => $psbt->toBase64(),
        ];
    }

    /**
     * @return array{0:string,1:Psbt} the payout address and the checked PSBT
     */
    private function verifiedRelease(array $deal, string $role, string $psbtText, string $payoutAddress): array
    {
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
                'signerPubKey' => $deal[$role . '_pubkey'],
                'signerExitPubKey' => $deal[$role . '_exit_pubkey'],
            ]);
        } catch (InvalidArgumentException $e) {
            throw new DealException('This is not a valid PSBT: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            throw new DealException($e->getMessage());
        }

        return [$payoutAddress, $psbt];
    }

    /**
     * Either party calls the arbiter. Only possible in a deal that has one, once the payment is confirmed.
     */
    private function openDispute(array $deal, string $role, string $reason, int $now): array
    {
        if ($role !== 'buyer' && $role !== 'seller') {
            throw new DealException('This step is not available at the moment.');
        }
        if (empty($deal['arbiter_pubkey'])) {
            throw new DealException('This deal has no arbiter.');
        }
        if (!in_array($deal['status'], DealStatus::DISPUTABLE, true)) {
            throw new DealException('A dispute is possible once the payment is confirmed and while the deal is running.');
        }
        if ($reason === '') {
            throw new DealException('Please describe the problem so that the arbiter can decide.');
        }

        return [
            'status' => DealStatus::DISPUTED,
            'dispute_opened_by' => $role,
            'dispute_reason' => mb_substr($reason, 0, 2000),
            'dispute_opened_at' => date('Y-m-d H:i:s', $now),
        ];
    }

    private function arbiterRule(array $deal, string $ruling, string $note): array
    {
        $this->expect($deal, DealStatus::DISPUTED);
        if ($ruling !== 'buyer' && $ruling !== 'seller') {
            throw new DealException('Choose who receives the money: the buyer or the seller.');
        }
        if ($note === '') {
            throw new DealException('Please give a short reason for the decision. Both parties see it.');
        }

        return ['status' => DealStatus::RULED, 'ruling' => $ruling, 'ruling_note' => mb_substr($note, 0, 2000)];
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
