<?php

declare(strict_types=1);

namespace Core\Media;

class Barcode
{
    /**
     * Code 128 (Subset B) bar-space patterns.
     * Each pattern represents the width of 3 bars and 3 spaces (sum = 11 modules).
     * @var array<int, string>
     */
    protected static array $patterns = [
        0 => '212222', 1 => '222122', 2 => '222221', 3 => '121223', 4 => '121322',
        5 => '131222', 6 => '122213', 7 => '122312', 8 => '132212', 9 => '221213',
        10 => '221312', 11 => '231212', 12 => '112232', 13 => '122132', 14 => '122231',
        15 => '113222', 16 => '123122', 17 => '123221', 18 => '223211', 19 => '221132',
        20 => '221231', 21 => '213212', 22 => '223112', 23 => '312131', 24 => '311222',
        25 => '321122', 26 => '321221', 27 => '312212', 28 => '322112', 29 => '322211',
        30 => '212123', 31 => '212321', 32 => '232121', 33 => '111323', 34 => '131123',
        35 => '131321', 36 => '112313', 37 => '132113', 38 => '132311', 39 => '211313',
        40 => '231113', 41 => '231311', 42 => '112133', 43 => '112331', 44 => '132131',
        45 => '113123', 46 => '113321', 47 => '133121', 48 => '313121', 49 => '211331',
        50 => '231131', 51 => '213113', 52 => '213311', 53 => '213131', 54 => '311123',
        55 => '311321', 56 => '331121', 57 => '312113', 58 => '312311', 59 => '332111',
        60 => '314111', 61 => '221411', 62 => '431111', 63 => '111224', 64 => '111422',
        65 => '121124', 66 => '121421', 67 => '141122', 68 => '141221', 69 => '112214',
        70 => '112412', 71 => '122114', 72 => '122411', 73 => '142112', 74 => '142211',
        75 => '241211', 76 => '221114', 77 => '413111', 78 => '241112', 79 => '134111',
        80 => '111242', 81 => '121142', 82 => '121241', 83 => '114212', 84 => '124112',
        85 => '124211', 86 => '411212', 87 => '421112', 88 => '421211', 89 => '212141',
        90 => '214121', 91 => '412121', 92 => '111143', 93 => '111341', 94 => '131141',
        95 => '114113', 96 => '114311', 97 => '411113', 98 => '411311', 99 => '113141',
        100 => '114131', 101 => '311141', 102 => '411131', 103 => '211412', // Start A
        104 => '211214', // Start B
        105 => '211232', // Start C
        106 => '2331112' // Stop code (7 elements, 13 modules)
    ];

    /**
     * Hasilkan gambar Barcode standar Code-128 dalam format vector SVG murni.
     */
    public static function svg(
        string $text,
        int $width = 300,
        int $height = 80,
        bool $showText = true,
        string $color = '#000000',
        string $bgColor = '#ffffff'
    ): string {
        $encoded = self::encodeCode128B($text);
        if (empty($encoded)) {
            $encoded = self::encodeCode128B('EMPTY');
        }

        $totalModules = strlen($encoded);
        $quietZone = 10; // 10 modules on each side
        $viewBoxWidth = $totalModules + ($quietZone * 2);
        $barHeight = $showText ? ($height - 20) : $height;

        $svg = [];
        $svg[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" style="background:%s;">',
            $viewBoxWidth,
            $height,
            $width,
            $height,
            htmlspecialchars($bgColor, ENT_QUOTES)
        );

        // Render bars
        $x = $quietZone;
        for ($i = 0; $i < $totalModules; $i++) {
            if ($encoded[$i] === '1') {
                $svg[] = sprintf(
                    '<rect x="%d" y="5" width="1" height="%d" fill="%s" />',
                    $x,
                    $barHeight,
                    htmlspecialchars($color, ENT_QUOTES)
                );
            }
            $x++;
        }

        // Render label text
        if ($showText) {
            $centerX = (int) ($viewBoxWidth / 2);
            $textY = $height - 4;
            $svg[] = sprintf(
                '<text x="%d" y="%d" text-anchor="middle" font-family="monospace, monospace" font-size="12" fill="%s">%s</text>',
                $centerX,
                $textY,
                htmlspecialchars($color, ENT_QUOTES),
                htmlspecialchars($text, ENT_QUOTES)
            );
        }

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    /**
     * Encode string to binary sequence ('1' for bar, '0' for space) using Code 128B.
     */
    protected static function encodeCode128B(string $text): string
    {
        $len = strlen($text);
        if ($len === 0) {
            return '';
        }

        $startCode = 104; // Start B
        $codes = [$startCode];
        $checksum = $startCode;

        for ($i = 0; $i < $len; $i++) {
            $ascii = ord($text[$i]);
            $code = $ascii - 32;
            if ($code < 0 || $code > 95) {
                $code = 0; // Space for unsupported chars
            }
            $codes[] = $code;
            $checksum += ($code * ($i + 1));
        }

        $checkDigit = $checksum % 103;
        $codes[] = $checkDigit;
        $codes[] = 106; // Stop code

        // Convert codes to 1/0 string
        $binary = '';
        foreach ($codes as $code) {
            $pattern = self::$patterns[$code] ?? '212222';
            $isBar = true;
            for ($p = 0; $p < strlen($pattern); $p++) {
                $width = (int) $pattern[$p];
                $binary .= str_repeat($isBar ? '1' : '0', $width);
                $isBar = !$isBar;
            }
        }

        return $binary;
    }
}
