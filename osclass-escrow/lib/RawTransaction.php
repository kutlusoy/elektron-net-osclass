<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

final class RawTransaction
{
    /** @var int */
    private $version;

    /** @var array<int,array{txid:string,vout:int,scriptSig:string,sequence:int}> */
    private $inputs;

    /** @var array<int,array{amountLep:int,scriptPubKey:string}> */
    private $outputs;

    /** @var int */
    private $locktime;

    /** @var bool */
    private $hasWitness;

    /**
     * @param array<int,array{txid:string,vout:int,scriptSig:string,sequence:int}> $inputs txid as display hex, scriptSig as raw bytes
     * @param array<int,array{amountLep:int,scriptPubKey:string}> $outputs scriptPubKey as raw bytes
     */
    public function __construct(int $version, array $inputs, array $outputs, int $locktime, bool $hasWitness = false)
    {
        $this->version = $version;
        $this->inputs = $inputs;
        $this->outputs = $outputs;
        $this->locktime = $locktime;
        $this->hasWitness = $hasWitness;
    }

    public static function fromHex(string $hex): self
    {
        $hex = strtolower(preg_replace('/\s+/', '', $hex));
        if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Not a hex string.');
        }

        return self::fromBinary(hex2bin($hex));
    }

    public static function fromBinary(string $bytes): self
    {
        $reader = new ByteReader($bytes);
        $version = $reader->uint32();
        $hasWitness = false;
        if ($reader->peek(2) === "\x00\x01") {
            $reader->bytes(2);
            $hasWitness = true;
        }
        $inputCount = $reader->varInt();
        if ($inputCount < 1 || $inputCount > 1000) {
            throw new InvalidArgumentException('Unsupported input count.');
        }
        $inputs = [];
        for ($i = 0; $i < $inputCount; $i++) {
            $txid = bin2hex(strrev($reader->bytes(32)));
            $vout = $reader->uint32();
            $scriptSig = $reader->bytes($reader->varInt());
            $sequence = $reader->uint32();
            $inputs[] = ['txid' => $txid, 'vout' => $vout, 'scriptSig' => $scriptSig, 'sequence' => $sequence];
        }
        $outputCount = $reader->varInt();
        if ($outputCount < 1 || $outputCount > 1000) {
            throw new InvalidArgumentException('Unsupported output count.');
        }
        $outputs = [];
        for ($i = 0; $i < $outputCount; $i++) {
            $amount = $reader->uint64();
            $outputs[] = ['amountLep' => $amount, 'scriptPubKey' => $reader->bytes($reader->varInt())];
        }
        if ($hasWitness) {
            for ($i = 0; $i < $inputCount; $i++) {
                $items = $reader->varInt();
                for ($j = 0; $j < $items; $j++) {
                    $reader->bytes($reader->varInt());
                }
            }
        }
        $locktime = $reader->uint32();
        if (!$reader->atEnd()) {
            throw new InvalidArgumentException('Trailing bytes after transaction.');
        }

        return new self($version, $inputs, $outputs, $locktime, $hasWitness);
    }

    public function serializeWithoutWitness(): string
    {
        $out = pack('V', $this->version) . ByteReader::encodeVarInt(count($this->inputs));
        foreach ($this->inputs as $input) {
            $out .= strrev(hex2bin($input['txid'])) . pack('V', $input['vout'])
                . ByteReader::encodeVarInt(strlen($input['scriptSig'])) . $input['scriptSig']
                . pack('V', $input['sequence']);
        }
        $out .= ByteReader::encodeVarInt(count($this->outputs));
        foreach ($this->outputs as $output) {
            $out .= pack('P', $output['amountLep'])
                . ByteReader::encodeVarInt(strlen($output['scriptPubKey'])) . $output['scriptPubKey'];
        }

        return $out . pack('V', $this->locktime);
    }

    public function txid(): string
    {
        return bin2hex(strrev(hash('sha256', hash('sha256', $this->serializeWithoutWitness(), true), true)));
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return array<int,array{txid:string,vout:int,scriptSig:string,sequence:int}>
     */
    public function inputs(): array
    {
        return $this->inputs;
    }

    /**
     * @return array<int,array{amountLep:int,scriptPubKey:string}>
     */
    public function outputs(): array
    {
        return $this->outputs;
    }

    public function locktime(): int
    {
        return $this->locktime;
    }

    public function hasWitness(): bool
    {
        return $this->hasWitness;
    }
}
