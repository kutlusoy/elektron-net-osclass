<?php

namespace ElektronNet\Escrow\Chain;

use RuntimeException;
use Throwable;

/**
 * Esplora/mempool-style REST client. Reads only the first page of an
 * address's transactions, which is enough for a one-time escrow address.
 */
final class EsploraChainDataProvider implements ChainDataProviderInterface
{
    /** @var string e.g. https://mempool.elektron-net.org/api */
    private $baseUrl;

    /** @var int seconds */
    private $timeoutSeconds;

    public function __construct(string $baseUrl, int $timeoutSeconds = 8)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getAddressTransactions(string $address): array
    {
        $json = $this->getJson('/address/' . rawurlencode($address) . '/txs');

        $result = [];
        foreach ($json as $tx) {
            $received = 0;
            foreach ($tx['vout'] ?? [] as $vout) {
                if (($vout['scriptpubkey_address'] ?? null) === $address) {
                    $received += (int) ($vout['value'] ?? 0);
                }
            }
            $confirmations = 0;
            if (!empty($tx['status']['confirmed'])) {
                $confirmations = $this->getConfirmations($tx['txid']);
            }
            $result[] = new AddressTransaction($tx['txid'], $received, $confirmations);
        }

        return $result;
    }

    /**
     * Only confirmed entries: a release must not depend on an output that a reorg could remove.
     */
    public function getFundingOutputs(string $address): array
    {
        $json = $this->getJson('/address/' . rawurlencode($address) . '/utxo');

        $result = [];
        foreach ($json as $utxo) {
            if (!empty($utxo['status']['confirmed'])) {
                $result[] = new FundingOutput($utxo['txid'], (int) $utxo['vout'], (int) $utxo['value']);
            }
        }

        return $result;
    }

    public function getConfirmations(string $txid): int
    {
        $status = $this->getJson('/tx/' . rawurlencode($txid) . '/status');
        if (empty($status['confirmed'])) {
            return 0;
        }

        $tipHeight = (int) $this->getJson('/blocks/tip/height');
        $txHeight = (int) ($status['block_height'] ?? 0);
        if ($txHeight <= 0) {
            return 0;
        }

        return max(0, $tipHeight - $txHeight + 1);
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $path)
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Chain-data request failed ({$this->baseUrl}{$path}): curl error {$errno}.");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("Chain-data request failed ({$this->baseUrl}{$path}): HTTP {$httpCode}.");
        }

        $decoded = json_decode($body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Chain-data request returned invalid JSON ({$this->baseUrl}{$path}).");
        }

        return $decoded;
    }
}
