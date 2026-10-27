<?php

declare(strict_types=1);

namespace Core\Media;

class QrCode
{
    /**
     * GF(256) log and antilog tables
     */
    protected static ?array $gfLog = null;
    protected static ?array $gfExp = null;

    /**
     * Table of Version capacities (Version 1-10, Level M Error Correction).
     * [Version => [TotalCodewords, DataCodewords, EccCodewords, AlignmentLocations]]
     */
    protected static array $versionSpecs = [
        1 => [26, 16, 10, []],
        2 => [44, 28, 16, [6, 18]],
        3 => [70, 44, 26, [6, 22]],
        4 => [100, 64, 36, [6, 26]],
        5 => [134, 86, 48, [6, 30]],
        6 => [172, 108, 64, [6, 34]],
        7 => [196, 124, 72, [6, 22, 38]],
        8 => [242, 154, 88, [6, 24, 42]],
        9 => [292, 182, 110, [6, 26, 46]],
        10 => [346, 216, 130, [6, 28, 50]],
    ];

    /**
     * Hasilkan gambar QR Code dalam format vector SVG murni.
     */
    public static function svg(
        string $text,
        int $size = 200,
        string $color = '#000000',
        string $bgColor = '#ffffff'
    ): string {
        $matrix = self::encode($text);
        $moduleCount = count($matrix);
        $quietZone = 4; // 4 modules border
        $viewBoxSize = $moduleCount + ($quietZone * 2);

        $svg = [];
        $svg[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" style="background:%s;">',
            $viewBoxSize,
            $viewBoxSize,
            $size,
            $size,
            htmlspecialchars($bgColor, ENT_QUOTES)
        );

        // Group black modules into path
        $pathData = [];
        for ($r = 0; $r < $moduleCount; $r++) {
            for ($c = 0; $c < $moduleCount; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = $c + $quietZone;
                    $y = $r + $quietZone;
                    $pathData[] = "M{$x},{$y}h1v1h-1z";
                }
            }
        }

        $svg[] = sprintf(
            '<path d="%s" fill="%s" />',
            implode(' ', $pathData),
            htmlspecialchars($color, ENT_QUOTES)
        );
        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    /**
     * Hasilkan data URI SVG (dapat langsung disematkan pada tag <img src="...">).
     */
    public static function dataUri(string $text, int $size = 200): string
    {
        $svg = self::svg($text, $size);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Encode text into QR binary matrix (array of rows of 0/1).
     */
    public static function encode(string $text): array
    {
        self::initGf();

        $dataLen = strlen($text);
        $version = 1;

        // Auto-detect version
        foreach (self::$versionSpecs as $v => $spec) {
            // Byte mode overhead: 4 bits mode + 8 bits length = 12 bits -> 2 bytes overhead
            if ($dataLen + 2 <= $spec[1]) {
                $version = $v;
                break;
            }
            $version = $v;
        }

        $spec = self::$versionSpecs[$version];
        $totalCodewords = $spec[0];
        $dataCodewordsCount = $spec[1];
        $eccCodewordsCount = $spec[2];

        // 1. Data Bitstream Encoding (Byte Mode: 0100)
        $bits = '0100';
        $bits .= str_pad(decbin($dataLen), 8, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $dataLen; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Terminator (up to 4 zeroes)
        $maxDataBits = $dataCodewordsCount * 8;
        $terminatorLen = min(4, $maxDataBits - strlen($bits));
        if ($terminatorLen > 0) {
            $bits .= str_repeat('0', $terminatorLen);
        }

        // Pad to byte boundary
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }

        // Pad bytes (0xEC, 0x11)
        $padBytes = ['11101100', '00010001'];
        $padIdx = 0;
        while (strlen($bits) < $maxDataBits) {
            $bits .= $padBytes[$padIdx % 2];
            $padIdx++;
        }

        // Convert data bits to byte codewords
        $dataCodewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $dataCodewords[] = bindec(substr($bits, $i, 8));
        }

        // 2. Generate Reed-Solomon Error Correction Codewords
        $eccCodewords = self::calculateReedSolomon($dataCodewords, $eccCodewordsCount);
        $finalCodewords = array_merge($dataCodewords, $eccCodewords);

        // 3. Populate Matrix
        $size = 17 + (4 * $version);
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // Function Patterns: Finder Patterns
        self::placeFinderPattern($matrix, $reserved, 0, 0);
        self::placeFinderPattern($matrix, $reserved, $size - 7, 0);
        self::placeFinderPattern($matrix, $reserved, 0, $size - 7);

        // Timing Patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0) ? 1 : 0;
            if ($matrix[6][$i] === null) {
                $matrix[6][$i] = $val;
                $reserved[6][$i] = true;
            }
            if ($matrix[$i][6] === null) {
                $matrix[$i][6] = $val;
                $reserved[$i][6] = true;
            }
        }

        // Dark module
        $matrix[4 * $version + 9][8] = 1;
        $reserved[4 * $version + 9][8] = true;

        // Alignment Patterns (for version >= 2)
        $alignPos = $spec[3];
        if (!empty($alignPos)) {
            foreach ($alignPos as $r) {
                foreach ($alignPos as $c) {
                    if ($matrix[$r][$c] === null) {
                        self::placeAlignmentPattern($matrix, $reserved, $r - 2, $c - 2);
                    }
                }
            }
        }

        // Reserve Format Information Area
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }

        // 4. Place Data Codewords (Zig-Zag)
        $allBits = '';
        foreach ($finalCodewords as $cw) {
            $allBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        // Add remainder bits if any
        $remainderBitsCount = ($version >= 2 && $version <= 6) ? 7 : 0;
        $allBits .= str_repeat('0', $remainderBitsCount);

        $bitIdx = 0;
        $bitLen = strlen($allBits);
        $upwards = true;

        for ($c = $size - 1; $c > 0; $c -= 2) {
            if ($c === 6) {
                $c--; // Skip timing column
            }
            $rows = $upwards ? range($size - 1, 0, -1) : range(0, $size - 1, 1);
            foreach ($rows as $r) {
                for ($dc = 0; $dc < 2; $dc++) {
                    $col = $c - $dc;
                    if (!$reserved[$r][$col]) {
                        $bit = ($bitIdx < $bitLen) ? (int) $allBits[$bitIdx] : 0;
                        // Apply Mask 0: (row + col) % 2 == 0
                        $mask = (($r + $col) % 2 === 0) ? 1 : 0;
                        $matrix[$r][$col] = $bit ^ $mask;
                        $bitIdx++;
                    }
                }
            }
            $upwards = !$upwards;
        }

        // 5. Place Format Information (Level M, Mask 0: 101010000010010)
        $formatBits = '101010000010010';
        self::placeFormatInfo($matrix, $formatBits);

        return $matrix;
    }

    protected static function placeFinderPattern(array &$matrix, array &$reserved, int $r, int $c): void
    {
        for ($y = -1; $y <= 7; $y++) {
            for ($x = -1; $x <= 7; $x++) {
                $row = $r + $y;
                $col = $c + $x;
                if ($row < 0 || $row >= count($matrix) || $col < 0 || $col >= count($matrix)) {
                    continue;
                }
                $isDark = ($y >= 0 && $y <= 6 && ($x === 0 || $x === 6)) ||
                          ($x >= 0 && $x <= 6 && ($y === 0 || $y === 6)) ||
                          ($y >= 2 && $y <= 4 && $x >= 2 && $x <= 4);
                $matrix[$row][$col] = $isDark ? 1 : 0;
                $reserved[$row][$col] = true;
            }
        }
    }

    protected static function placeAlignmentPattern(array &$matrix, array &$reserved, int $r, int $c): void
    {
        for ($y = 0; $y < 5; $y++) {
            for ($x = 0; $x < 5; $x++) {
                $row = $r + $y;
                $col = $c + $x;
                $isDark = ($y === 0 || $y === 4 || $x === 0 || $x === 4 || ($y === 2 && $x === 2));
                $matrix[$row][$col] = $isDark ? 1 : 0;
                $reserved[$row][$col] = true;
            }
        }
    }

    protected static function placeFormatInfo(array &$matrix, string $formatBits): void
    {
        $size = count($matrix);
        // Top-left
        $coordsTopLeft = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];
        // Split bottom-left and top-right
        $coordsSplit = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1]
        ];

        for ($i = 0; $i < 15; $i++) {
            $val = (int) $formatBits[$i];
            $matrix[$coordsTopLeft[$i][0]][$coordsTopLeft[$i][1]] = $val;
            $matrix[$coordsSplit[$i][0]][$coordsSplit[$i][1]] = $val;
        }
    }

    protected static function calculateReedSolomon(array $data, int $eccCount): array
    {
        // Build generator polynomial g(x) = (x - a^0)(x - a^1)...(x - a^(n-1))
        $gen = [1];
        for ($i = 0; $i < $eccCount; $i++) {
            $term = [1, self::$gfExp[$i]];
            $gen = self::polyMultiply($gen, $term);
        }

        // Pad data with zeroes
        $paddedData = array_merge($data, array_fill(0, $eccCount, 0));

        // Polynomial division
        $leadCoeff = 0;
        for ($i = 0; $i < count($data); $i++) {
            $lead = $paddedData[$i];
            if ($lead !== 0) {
                $leadLog = self::$gfLog[$lead];
                for ($j = 0; $j < count($gen); $j++) {
                    $paddedData[$i + $j] ^= self::$gfExp[($leadLog + self::$gfLog[$gen[$j]]) % 255];
                }
            }
        }

        return array_slice($paddedData, count($data));
    }

    protected static function polyMultiply(array $p1, array $p2): array
    {
        $result = array_fill(0, count($p1) + count($p2) - 1, 0);
        for ($i = 0; $i < count($p1); $i++) {
            for ($j = 0; $j < count($p2); $j++) {
                if ($p1[$i] !== 0 && $p2[$j] !== 0) {
                    $log = self::$gfLog[$p1[$i]] + self::$gfLog[$p2[$j]];
                    $result[$i + $j] ^= self::$gfExp[$log % 255];
                }
            }
        }
        return $result;
    }

    protected static function initGf(): void
    {
        if (self::$gfExp !== null) {
            return;
        }

        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);

        $val = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $val;
            self::$gfLog[$val] = $i;
            $val <<= 1;
            if ($val & 0x100) {
                $val ^= 0x11D; // Modulo polynomial 285
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 255];
        }
    }
}
