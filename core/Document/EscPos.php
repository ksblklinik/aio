<?php

declare(strict_types=1);

namespace Core\Document;

class EscPos
{
    private string $buffer = '';
    private int $cols = 32; // 32 karakter untuk kertas 58mm, 48 karakter untuk 80mm

    public function __construct(int $cols = 32)
    {
        $this->cols = $cols;
        $this->initialize();
    }

    public static function create(int $cols = 32): self
    {
        return new self($cols);
    }

    /**
     * Inisialisasi printer (reset format ke default)
     */
    public function initialize(): self
    {
        $this->buffer .= "\x1B\x40";
        return $this;
    }

    /**
     * Atur perataan teks (left, center, right)
     */
    public function align(string $align = 'left'): self
    {
        $code = match (strtolower($align)) {
            'center' => "\x01",
            'right' => "\x02",
            default => "\x00",
        };
        $this->buffer .= "\x1B\x61" . $code;
        return $this;
    }

    /**
     * Aktifkan atau nonaktifkan teks tebal (bold)
     */
    public function bold(bool $enable = true): self
    {
        $this->buffer .= "\x1B\x45" . ($enable ? "\x01" : "\x00");
        return $this;
    }

    /**
     * Aktifkan atau nonaktifkan teks bergaris bawah (underline)
     */
    public function underline(bool $enable = true): self
    {
        $this->buffer .= "\x1B\x2D" . ($enable ? "\x01" : "\x00");
        return $this;
    }

    /**
     * Aktifkan atau nonaktifkan ukuran font ganda (besar)
     */
    public function doubleSize(bool $enable = true): self
    {
        $this->buffer .= "\x1D\x21" . ($enable ? "\x11" : "\x00");
        return $this;
    }

    /**
     * Tulis teks biasa dengan baris baru (LF)
     */
    public function line(string $text = ''): self
    {
        $this->buffer .= $text . "\x0A";
        return $this;
    }

    /**
     * Buat garis pembatas putus-putus sesuai lebar kolom
     */
    public function divider(string $char = '-'): self
    {
        $this->buffer .= str_repeat($char, $this->cols) . "\x0A";
        return $this;
    }

    /**
     * Cetak baris dua kolom rata kiri dan kanan (misal: "Total       Rp 50.000")
     */
    public function row(string $left, string $right): self
    {
        $lenLeft = mb_strlen($left);
        $lenRight = mb_strlen($right);

        if ($lenLeft + $lenRight >= $this->cols) {
            $space = 1;
            $left = mb_substr($left, 0, max(1, $this->cols - $lenRight - 1));
        } else {
            $space = $this->cols - $lenLeft - $lenRight;
        }

        $this->buffer .= $left . str_repeat(' ', $space) . $right . "\x0A";
        return $this;
    }

    /**
     * Majukan kertas sebanyak $n baris
     */
    public function feed(int $lines = 3): self
    {
        $this->buffer .= "\x1B\x64" . chr(max(1, $lines));
        return $this;
    }

    /**
     * Perintah pemotong kertas otomatis (auto-cutter)
     */
    public function cut(bool $partial = true): self
    {
        $this->buffer .= "\x1D\x56" . ($partial ? "\x01" : "\x00");
        return $this;
    }

    /**
     * Buka laci kasir otomatis (cash drawer kick)
     */
    public function openDrawer(): self
    {
        $this->buffer .= "\x1B\x70\x00\x19\xFA";
        return $this;
    }

    /**
     * Dapatkan string byte binary mentah siap dikirim ke socket printer atau Web Bluetooth
     */
    public function getBinary(): string
    {
        return $this->buffer;
    }

    /**
     * Dapatkan representasi Base64 untuk transfer via JSON API ke frontend Web Bluetooth
     */
    public function toBase64(): string
    {
        return base64_encode($this->buffer);
    }
}
