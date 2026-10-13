<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * The plugin's admin settings as one validated object.
 */
final class Config
{
    /** @var string */
    private $network;

    /** @var string[] */
    private $chainDataEndpoints;

    /** @var int */
    private $requiredConfirmations;

    /** @var TimeoutPolicy */
    private $timeoutPolicy;

    /**
     * @param string[] $chainDataEndpoints
     */
    public function __construct(string $network, array $chainDataEndpoints, int $requiredConfirmations, TimeoutPolicy $timeoutPolicy)
    {
        if (!in_array($network, ['mainnet', 'testnet', 'regtest'], true)) {
            throw new InvalidArgumentException("Unknown network '{$network}'.");
        }
        if (count($chainDataEndpoints) < 1) {
            throw new InvalidArgumentException('At least one chain-data endpoint is required.');
        }
        if ($requiredConfirmations < 1) {
            throw new InvalidArgumentException('requiredConfirmations must be at least 1.');
        }
        $this->network = $network;
        $this->chainDataEndpoints = array_values($chainDataEndpoints);
        $this->requiredConfirmations = $requiredConfirmations;
        $this->timeoutPolicy = $timeoutPolicy;
    }

    public function network(): string
    {
        return $this->network;
    }

    /**
     * @return string[]
     */
    public function chainDataEndpoints(): array
    {
        return $this->chainDataEndpoints;
    }

    public function requiredConfirmations(): int
    {
        return $this->requiredConfirmations;
    }

    public function timeoutPolicy(): TimeoutPolicy
    {
        return $this->timeoutPolicy;
    }
}
