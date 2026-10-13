<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * The escrow contract as one P2WSH miniscript, so every spending path is
 * enforced on chain and every wallet that understands the descriptor can
 * build and sign it on its own:
 *
 *   or_d(multi(2,K1,K2),
 *        or_i(and_v(v:pk(BUYER_EXIT),after(T1)),
 *             and_v(v:pk(SELLER_EXIT),after(T2))))
 *
 * Both parties together (their main keys, K1 and K2 in lexicographic order)
 * can always spend. After T1 the buyer alone can reclaim with the exit key,
 * after T2 the seller alone can claim with theirs. Miniscript forbids the
 * same key twice in one script, which is why each party has a main key and
 * a separate exit key.
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

    public static function witnessScriptHex(string $sellerKey, string $sellerExitKey, string $buyerKey, string $buyerExitKey, int $refundLocktime, int $claimLocktime): string
    {
        $keys = array_map('strtolower', [$sellerKey, $sellerExitKey, $buyerKey, $buyerExitKey]);
        foreach ($keys as $key) {
            if (!PubKey::isValidCompressed($key)) {
                throw new InvalidArgumentException('Not a valid compressed public key.');
            }
        }
        if (count(array_unique($keys)) !== 4) {
            throw new InvalidArgumentException('All four public keys must be different.');
        }
        [$sellerKey, $sellerExitKey, $buyerKey, $buyerExitKey] = $keys;
        self::assertLocktimes($refundLocktime, $claimLocktime);

        [$low, $high] = self::sortedKeys($sellerKey, $buyerKey);

        return self::OP_2 . '21' . $low . '21' . $high . self::OP_2 . self::OP_CHECKMULTISIG
            . self::OP_IFDUP . self::OP_NOTIF
            . self::OP_IF . '21' . $buyerExitKey . self::OP_CHECKSIGVERIFY . self::pushNumber($refundLocktime) . self::OP_CLTV
            . self::OP_ELSE . '21' . $sellerExitKey . self::OP_CHECKSIGVERIFY . self::pushNumber($claimLocktime) . self::OP_CLTV
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
