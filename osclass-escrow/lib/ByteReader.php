<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

final class ByteReader
{
    /** @var string */
    private $data;

    /** @var int */
    private $offset = 0;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    public function bytes(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new InvalidArgumentException('Unexpected end of data.');
        }
        $chunk = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $chunk;
    }

    public function peek(int $length): string
    {
        return substr($this->data, $this->offset, $length);
    }

    public function uint32(): int
    {
        return unpack('V', $this->bytes(4))[1];
    }

    public function uint64(): int
    {
        $value = unpack('P', $this->bytes(8))[1];
        if ($value < 0) {
            throw new InvalidArgumentException('Amount out of range.');
        }

        return $value;
    }

    public function varInt(): int
    {
        $first = ord($this->bytes(1));
        if ($first < 0xfd) {
            return $first;
        }
        if ($first === 0xfd) {
            return unpack('v', $this->bytes(2))[1];
        }
        if ($first === 0xfe) {
            return $this->uint32();
        }

        return $this->uint64();
    }

    public function atEnd(): bool
    {
        return $this->offset === strlen($this->data);
    }

    public static function varIntLength(int $value): int
    {
        return strlen(self::encodeVarInt($value));
    }

    public static function encodeVarInt(int $value): string
    {
        if ($value < 0xfd) {
            return chr($value);
        }
        if ($value <= 0xffff) {
            return "\xfd" . pack('v', $value);
        }
        if ($value <= 0xffffffff) {
            return "\xfe" . pack('V', $value);
        }

        return "\xff" . pack('P', $value);
    }
}
