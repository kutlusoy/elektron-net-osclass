<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * The escrow contract as one P2WSH miniscript, so every spending path is
 * enforced on chain and every wallet that understands the descriptor can
 * build and sign it on its own:
 *
 *   or_d(multi(2,K1,K2),
 *        or_i(and_v(v:pk(BUYER),after(T1)), and_v(v:pk(SELLER),after(T2))))
 *
 * Both parties together can always spend. After T1 the buyer alone can
 * reclaim, after T2 the seller alone can claim. K1 and K2 are the two
 * keys in lexicographic order.
 */
final class EscrowScript
{
    private const OP_2 = '52';
    private const OP_CHECKMULTISIG = 'ae';
    private const OP_IFDUP = '73';
    private const OP_NOTIF = '64';
    private const OP_IF = '63';
    private const OP_ELSE = '67';
    private const OP_ENDIF = '68';
    private const OP_CHECKSIGVERIFY = 'ad';
    private const OP_CLTV = 'b1';
    private const MIN_TIMESTAMP_LOCKTIME = 500000000;
    private const MAX_LOCKTIME = 0xffffffff;

    private function __construct()
    {
    }

    public static function witnessScriptHex(string $sellerKey, string $buyerKey, int $refundLocktime, int $claimLocktime): string
    {
        $sellerKey = strtolower($sellerKey);
        $buyerKey = strtolower($buyerKey);
        foreach ([$sellerKey, $buyerKey] as $key) {
            if (!PubKey::isValidCompressed($key)) {
                throw new InvalidArgumentException('Not a valid compressed public key.');
            }
        }
        if ($sellerKey === $buyerKey) {
            throw new InvalidArgumentException('Both parties must use different public keys.');
        }
        self::assertLocktimes($refundLocktime, $claimLocktime);

        [$low, $high] = self::sortedKeys($sellerKey, $buyerKey);

        return self::OP_2 . '21' . $low . '21' . $high . self::OP_2 . self::OP_CHECKMULTISIG
            . self::OP_IFDUP . self::OP_NOTIF
            . self::OP_IF . '21' . $buyerKey . self::OP_CHECKSIGVERIFY . self::pushNumber($refundLocktime) . self::OP_CLTV
            . self::OP_ELSE . '21' . $sellerKey . self::OP_CHECKSIGVERIFY . self::pushNumber($claimLocktime) . self::OP_CLTV
            . self::OP_ENDIF . self::OP_ENDIF;
    }

    public static function scriptPubKeyHex(string $witnessScriptHex): string
    {
        return '0020' . hash('sha256', hex2bin($witnessScriptHex));
    }

    public static function address(string $witnessScriptHex, NetworkParams $network): string
    {
        return Bech32::encodeSegwit($network->hrp(), 0, hash('sha256', hex2bin($witnessScriptHex), true));
    }

    /**
     * @return string[] the two keys in script order
     */
    public static function sortedKeys(string $keyA, string $keyB): array
    {
        $keys = [strtolower($keyA), strtolower($keyB)];
        sort($keys, SORT_STRING);

        return $keys;
    }

    public static function assertLocktimes(int $refundLocktime, int $claimLocktime): void
    {
        foreach ([$refundLocktime, $claimLocktime] as $locktime) {
            if ($locktime < self::MIN_TIMESTAMP_LOCKTIME || $locktime > self::MAX_LOCKTIME) {
                throw new InvalidArgumentException('Locktimes must be unix timestamps.');
            }
        }
        if ($refundLocktime >= $claimLocktime) {
            throw new InvalidArgumentException('The refund time must be earlier than the claim time.');
        }
    }

    private static function pushNumber(int $value): string
    {
        $bytes = '';
        $rest = $value;
        while ($rest > 0) {
            $bytes .= chr($rest & 0xff);
            $rest >>= 8;
        }
        if ((ord($bytes[strlen($bytes) - 1]) & 0x80) !== 0) {
            $bytes .= "\x00";
        }

        return sprintf('%02x', strlen($bytes)) . bin2hex($bytes);
    }
}
