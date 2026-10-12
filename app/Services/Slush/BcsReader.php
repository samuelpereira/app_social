<?php

namespace App\Services\Slush;

use App\Exceptions\InvalidSlushSignatureException;

class BcsReader
{
    private int $offset = 0;

    public function __construct(private string $bytes) {}

    public function skipProofPoints(): void
    {
        $this->vectorOfStrings();
        $count = $this->uleb128();

        for ($index = 0; $index < $count; $index++) {
            $this->vectorOfStrings();
        }

        $this->vectorOfStrings();
    }

    public function string(): string
    {
        $length = $this->uleb128();
        $value = substr($this->bytes, $this->offset, $length);

        if (strlen($value) !== $length) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $this->offset += $length;

        return $value;
    }

    public function u8(): int
    {
        if ($this->offset >= strlen($this->bytes)) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $value = ord($this->bytes[$this->offset]);
        $this->offset++;

        return $value;
    }

    private function vectorOfStrings(): void
    {
        $count = $this->uleb128();

        for ($index = 0; $index < $count; $index++) {
            $this->string();
        }
    }

    private function uleb128(): int
    {
        $result = 0;
        $shift = 0;

        do {
            if ($this->offset >= strlen($this->bytes) || $shift > 28) {
                throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
            }

            $byte = ord($this->bytes[$this->offset]);
            $this->offset++;
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return $result;
    }
}
