<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

final class NetworkParams
{
    /** @var string */
    private $hrp;

    /** @var int */
    private $p2pkhVersion;

    /** @var int */
    private $p2shVersion;

    private function __construct(string $hrp, int $p2pkhVersion, int $p2shVersion)
    {
        $this->hrp = $hrp;
        $this->p2pkhVersion = $p2pkhVersion;
        $this->p2shVersion = $p2shVersion;
    }

    public static function forNetwork(string $network): self
    {
        switch ($network) {
            case 'mainnet':
                return new self('be', 0x00, 0x05);
            case 'testnet':
                return new self('tb', 0x6f, 0xc4);
            case 'regtest':
                return new self('bcrt', 0x6f, 0xc4);
            default:
                throw new InvalidArgumentException("Unknown network '{$network}'.");
        }
    }

    public function hrp(): string
    {
        return $this->hrp;
    }

    public function p2pkhVersion(): int
    {
        return $this->p2pkhVersion;
    }

    public function p2shVersion(): int
    {
        return $this->p2shVersion;
    }
}
