<?php

declare(strict_types=1);

namespace App\Services;

use Smalot\PdfParser\Parser;

/**
 * Counts PDF pages, including files whose page tree is stored in compressed object streams.
 */
final class PdfPageCounter
{
    public function count(string $filePath): ?int
    {
        $previousMemory = ini_get('memory_limit');
        ini_set('memory_limit', '512M');
        try {
            $fromParser = $this->countWithParser($filePath);
            if ($fromParser !== null) {
                return $fromParser;
            }
            $fromTree = $this->countFromUncompressedTree($filePath);
            if ($fromTree === null) {
                error_log('PDF page count failed for receipt file.');
            }
            return $fromTree;
        } finally {
            if (is_string($previousMemory) && $previousMemory !== '') {
                ini_set('memory_limit', $previousMemory);
            }
        }
    }

    private function countWithParser(string $filePath): ?int
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($filePath);
            $count = count($pdf->getPages());
            return $count > 0 ? $count : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function countFromUncompressedTree(string $filePath): ?int
    {
        $raw = file_get_contents($filePath);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $found = preg_match_all('/\/Type\s*\/Pages.{0,200}?\/Count\s+(\d+)/s', $raw, $matches);
        if ($found === false || $found === 0) {
            return null;
        }
        $counts = array_map('intval', $matches[1]);
        $count = max($counts);
        return $count > 0 ? $count : null;
    }
}
