<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * Parsed BIP174 PSBT. Maps are kept as ordered lists of [key, value] byte
 * strings so a PSBT re-serializes exactly as it was parsed.
 */
final class Psbt
{
    public const MAGIC = "psbt\xff";
    public const MAX_BYTES = 1048576;

    /** @var array<int,array{0:string,1:string}> */
    private $globalMap;

    /** @var array<int,array<int,array{0:string,1:string}>> */
    private $inputMaps;

    /** @var array<int,array<int,array{0:string,1:string}>> */
    private $outputMaps;

    /** @var RawTransaction */
    private $tx;

    /**
     * @param array<int,array{0:string,1:string}> $globalMap
     * @param array<int,array<int,array{0:string,1:string}>> $inputMaps
     * @param array<int,array<int,array{0:string,1:string}>> $outputMaps
     */
    public function __construct(array $globalMap, array $inputMaps, array $outputMaps, RawTransaction $tx)
    {
        $this->globalMap = $globalMap;
        $this->inputMaps = $inputMaps;
        $this->outputMaps = $outputMaps;
        $this->tx = $tx;
    }

    /**
     * Accepts base64 (wallet default) or hex.
     *
     * @throws InvalidArgumentException on anything that is not a well-formed PSBT
     */
    public static function parse(string $text): self
    {
        // A '+' that a form layer turned into a space is restored when the text has no line breaks.
        $text = trim($text);
        if (strpos($text, ' ') !== false && !preg_match('/[\r\n]/', $text)) {
            $text = str_replace(' ', '+', $text);
        }
        $text = preg_replace('/\s+/', '', $text);
        if ($text === '' || strlen($text) > self::MAX_BYTES * 2) {
            throw new InvalidArgumentException('Empty or oversized PSBT.');
        }
        if (ctype_xdigit($text) && strlen($text) % 2 === 0 && strncasecmp($text, '70736274ff', 10) === 0) {
            $bytes = hex2bin($text);
        } else {
            $bytes = base64_decode($text, true);
            if ($bytes === false) {
                throw new InvalidArgumentException('Not valid base64 or hex.');
            }
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('PSBT is too large.');
        }

        return self::fromBinary($bytes);
    }

    public static function fromBinary(string $bytes): self
    {
        $reader = new ByteReader($bytes);
        if ($reader->bytes(5) !== self::MAGIC) {
            throw new InvalidArgumentException('Missing PSBT magic bytes.');
        }
        $globalMap = self::readMap($reader);
        $tx = null;
        foreach ($globalMap as [$key, $value]) {
            if ($key === "\x00") {
                $tx = RawTransaction::fromBinary($value);
            }
        }
        if ($tx === null) {
            throw new InvalidArgumentException('PSBT has no unsigned transaction.');
        }
        foreach ($tx->inputs() as $input) {
            if ($input['scriptSig'] !== '') {
                throw new InvalidArgumentException('The PSBT transaction must be unsigned.');
            }
        }
        $inputMaps = [];
        foreach ($tx->inputs() as $unused) {
            $inputMaps[] = self::readMap($reader);
        }
        $outputMaps = [];
        foreach ($tx->outputs() as $unused) {
            $outputMaps[] = self::readMap($reader);
        }
        if (!$reader->atEnd()) {
            throw new InvalidArgumentException('Trailing bytes after PSBT.');
        }

        return new self($globalMap, $inputMaps, $outputMaps, $tx);
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private static function readMap(ByteReader $reader): array
    {
        $pairs = [];
        $seen = [];
        while (true) {
            $keyLength = $reader->varInt();
            if ($keyLength === 0) {
                return $pairs;
            }
            $key = $reader->bytes($keyLength);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate key in PSBT map.');
            }
            $seen[$key] = true;
            $pairs[] = [$key, $reader->bytes($reader->varInt())];
        }
    }

    public function toBinary(): string
    {
        $out = self::MAGIC . self::writeMap($this->globalMap);
        foreach ($this->inputMaps as $map) {
            $out .= self::writeMap($map);
        }
        foreach ($this->outputMaps as $map) {
            $out .= self::writeMap($map);
        }

        return $out;
    }

    public function toBase64(): string
    {
        return base64_encode($this->toBinary());
    }

    /**
     * @param array<int,array{0:string,1:string}> $pairs
     */
    private static function writeMap(array $pairs): string
    {
        $out = '';
        foreach ($pairs as [$key, $value]) {
            $out .= ByteReader::encodeVarInt(strlen($key)) . $key . ByteReader::encodeVarInt(strlen($value)) . $value;
        }

        return $out . "\x00";
    }

    public function tx(): RawTransaction
    {
        return $this->tx;
    }

    /**
     * @return array<int,array<int,array{0:string,1:string}>>
     */
    public function inputMaps(): array
    {
        return $this->inputMaps;
    }

    /**
     * @return array<int,array<int,array{0:string,1:string}>>
     */
    public function outputMaps(): array
    {
        return $this->outputMaps;
    }

    /**
     * @param array<int,array{0:string,1:string}> $map
     * @return array<int,array{0:string,1:string}> every pair whose key type byte is $type
     */
    public static function pairsOfType(array $map, int $type): array
    {
        return array_values(array_filter($map, function (array $pair) use ($type): bool {
            return $pair[0] !== '' && ord($pair[0][0]) === $type;
        }));
    }
}
