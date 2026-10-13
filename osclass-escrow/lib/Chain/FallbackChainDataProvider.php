<?php

namespace ElektronNet\Escrow\Chain;

use RuntimeException;
use Throwable;

/**
 * Tries each configured chain-data endpoint in order, moving to the next
 * one if the current one throws or times out. The endpoint list comes
 * from the admin settings.
 */
final class FallbackChainDataProvider implements ChainDataProviderInterface
{
    /** @var ChainDataProviderInterface[] in priority order */
    private $providers;

    /**
     * @param ChainDataProviderInterface[] $providers
     */
    public function __construct(array $providers)
    {
        if (count($providers) < 1) {
            throw new RuntimeException('FallbackChainDataProvider needs at least one provider.');
        }
        $this->providers = array_values($providers);
    }

    public function getAddressTransactions(string $address): array
    {
        return $this->withFallback(function (ChainDataProviderInterface $provider) use ($address) {
            return $provider->getAddressTransactions($address);
        });
    }

    public function getFundingOutputs(string $address): array
    {
        return $this->withFallback(function (ChainDataProviderInterface $provider) use ($address) {
            return $provider->getFundingOutputs($address);
        });
    }

    public function getConfirmations(string $txid): int
    {
        return $this->withFallback(function (ChainDataProviderInterface $provider) use ($txid) {
            return $provider->getConfirmations($txid);
        });
    }

    /**
     * @param callable(ChainDataProviderInterface): mixed $call
     * @return mixed
     */
    private function withFallback(callable $call)
    {
        $lastError = null;
        foreach ($this->providers as $provider) {
            try {
                return $call($provider);
            } catch (Throwable $e) {
                $lastError = $e;
                continue;
            }
        }

        throw new RuntimeException('All configured chain-data endpoints failed.', 0, $lastError);
    }
}
