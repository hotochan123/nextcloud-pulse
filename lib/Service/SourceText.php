<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

/**
 * The plain text of a file the moderator picked for an AI quiz draft
 * (AiDraftService): only this text goes to the AI, never the file.
 *
 * Text and Markdown as they are; Word, PowerPoint and OpenDocument (ZIP
 * with XML) through their text nodes; PDF through the text operators of its
 * content streams (literal strings only: scanned pages and CID fonts give no
 * text, and the caller says so). No XML parser runs: the tags are stripped
 * with regular expressions, so no entity (external or not) is ever resolved.
 * Each ZIP entry and each PDF stream is capped (MAX_PART_BYTES unpacked), so
 * a small hostile file cannot blow up into gigabytes. Over the whole file,
 * reading stops once TEXT_MAX_BYTES of text are collected (more than the AI
 * draft ever sends) or UNPACKED_MAX_BYTES were unpacked, whichever comes
 * first: a small file made of many highly compressed parts costs no more.
 */
class SourceText {
    /** Unpacked size of one ZIP entry or one PDF stream that is still read. */
    public const MAX_PART_BYTES = 10 * 1024 * 1024;
    /** Text collected from one file, at most (bytes; AiDraftService sends 15,000 characters). */
    public const TEXT_MAX_BYTES = 64 * 1024;
    /** Unpacked bytes of all ZIP entries or PDF streams of one file together, at most. */
    public const UNPACKED_MAX_BYTES = 50 * 1024 * 1024;
    /** PDF streams looked at, at most. */
    private const MAX_STREAMS = 5000;

    private const TEXT_EXT = ['txt', 'md', 'markdown', 'csv', 'text'];

    /**
     * @return string|null the text (UTF-8, may be empty); null = file type not supported
     */
    public static function extract(string $name, string $mime, string $bytes): ?string {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::TEXT_EXT, true) || ($ext === '' && str_starts_with($mime, 'text/'))) {
            return self::utf8($bytes);
        }
        return match ($ext) {
            'pdf' => self::pdf($bytes),
            'docx' => self::zipXml($bytes, ['word/document.xml']),
            'pptx' => self::zipXml($bytes, null),
            'odt', 'odp' => self::zipXml($bytes, ['content.xml']),
            default => null,
        };
    }

    /** Valid UTF-8 as it is (without a BOM), anything else read as Windows-1252. */
    public static function utf8(string $s): string {
        if (str_starts_with($s, "\u{FEFF}")) {
            $s = substr($s, 3);
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return $s;
    }

    /**
     * Text of XML entries of a ZIP: the named ones, or (null) the slides of a
     * PowerPoint file in slide order (slide2 before slide10).
     *
     * @param list<string>|null $entries
     */
    private static function zipXml(string $bytes, ?array $entries): string {
        $tmp = tempnam(sys_get_temp_dir(), 'pulse-src');
        if ($tmp === false) {
            return '';
        }
        try {
            if (file_put_contents($tmp, $bytes) === false) {
                return '';
            }
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) {
                return '';
            }
            if ($entries === null) {
                $slides = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = (string)$zip->getNameIndex($i);
                    if (preg_match('#\Appt/slides/slide(\d+)\.xml\z#', $n, $m) === 1) {
                        $slides[(int)$m[1]] = $n;
                    }
                }
                ksort($slides);
                $entries = array_values($slides);
            }
            $parts = [];
            $unpacked = 0;
            $collected = 0;
            foreach ($entries as $entry) {
                $stat = $zip->statName($entry);
                if ($stat === false || $stat['size'] > self::MAX_PART_BYTES) {
                    continue;
                }
                $unpacked += $stat['size'];
                if ($unpacked > self::UNPACKED_MAX_BYTES) {
                    break;
                }
                $xml = $zip->getFromName($entry);
                if (is_string($xml)) {
                    $text = self::xmlText($xml);
                    $parts[] = $text;
                    $collected += strlen($text);
                    if ($collected >= self::TEXT_MAX_BYTES) {
                        break;
                    }
                }
            }
            $zip->close();
            return self::cut(trim(implode("\n\n", $parts)));
        } finally {
            @unlink($tmp);
        }
    }

    /** Text nodes of an Office/OpenDocument XML part, one line per paragraph. */
    public static function xmlText(string $xml): string {
        // paragraph and line ends, tabs and ODF's space runs become white space
        $xml = (string)preg_replace('#</(?:w:p|a:p|text:p|text:h)>|<(?:w:br|a:br|text:line-break)\b[^>]*/>#', "\n", $xml);
        $xml = (string)preg_replace('#<(?:w:tab|text:tab|text:s)\b[^>]*/>#', ' ', $xml);
        // no entity is resolved: the XML is never parsed, only its tags dropped
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = (string)preg_replace('/[ \t]+/', ' ', $text);
        $text = (string)preg_replace('/ *\n[ \n]*/', "\n", $text);
        return trim($text);
    }

    /** Text of a PDF: the strings shown by Tj, TJ, ' and " inside BT … ET of its streams. */
    public static function pdf(string $bytes): string {
        $out = [];
        $offset = 0;
        $count = 0;
        $unpacked = 0;
        $collected = 0;
        while ($count++ < self::MAX_STREAMS && $collected < self::TEXT_MAX_BYTES && $unpacked <= self::UNPACKED_MAX_BYTES && ($pos = strpos($bytes, 'stream', $offset)) !== false) {
            // "endstream" also contains "stream"
            if ($pos >= 3 && substr($bytes, $pos - 3, 3) === 'end') {
                $offset = $pos + 6;
                continue;
            }
            $start = $pos + 6;
            if (($bytes[$start] ?? '') === "\r") {
                $start++;
            }
            if (($bytes[$start] ?? '') === "\n") {
                $start++;
            }
            $end = strpos($bytes, 'endstream', $start);
            if ($end === false) {
                break;
            }
            // the stream's dictionary: the last "<<" in the 1 KiB before the keyword, up to it
            $window = substr($bytes, max(0, $pos - 1024), min($pos, 1024));
            $dictStart = strrpos($window, '<<');
            $dict = $dictStart === false ? '' : substr($window, $dictStart);
            $offset = $end + 9;
            if (str_contains($dict, '/Image') || str_contains($dict, '/Length1') || str_contains($dict, '/FontFile')) {
                continue;
            }
            $data = substr($bytes, $start, $end - $start);
            if (str_contains($dict, '/FlateDecode')) {
                $data = @gzuncompress(rtrim($data, "\r\n"), self::MAX_PART_BYTES);
                if ($data === false) {
                    continue;
                }
            } elseif (preg_match('#/Filter\s*/#', $dict) === 1) {
                continue; // other filters (DCT, LZW, ASCII85 …) carry no readable text here
            }
            $unpacked += strlen($data);
            $text = self::pdfContent($data, self::TEXT_MAX_BYTES - $collected);
            if ($text !== '') {
                $out[] = $text;
                $collected += strlen($text);
            }
        }
        return self::cut(trim(implode("\n", $out)));
    }

    /** At most TEXT_MAX_BYTES, cut at a character boundary. */
    private static function cut(string $text): string {
        return strlen($text) <= self::TEXT_MAX_BYTES ? $text : mb_strcut($text, 0, self::TEXT_MAX_BYTES, 'UTF-8');
    }

    /** The shown strings of one content stream, until about $budget bytes of text are collected. */
    private static function pdfContent(string $s, int $budget): string {
        if (!str_contains($s, 'BT')) {
            return '';
        }
        $lines = [];
        $line = '';
        $len = strlen($s);
        $inText = false;
        /** @var list<string> strings since the last operator */
        $pending = [];
        $i = 0;
        // bytes in $lines; the loop ends once they and the open line reach the budget
        $size = 0;
        while ($i < $len && $size + strlen($line) < $budget) {
            $c = $s[$i];
            if ($c === '(') {
                [$str, $i] = self::pdfString($s, $i);
                $pending[] = $str;
                continue;
            }
            if ($c === '[') {
                $pending = [];
                $i++;
                continue;
            }
            if ($c === '<' && ($s[$i + 1] ?? '') !== '<') {
                // hex strings (mostly CID glyph ids): skipped
                $close = strpos($s, '>', $i);
                $i = $close === false ? $len : $close + 1;
                continue;
            }
            if ($c === '-' || ctype_digit($c) || $c === '.') {
                preg_match('/\G-?[\d.]+/', $s, $m, 0, $i);
                $num = $m[0] ?? $c;
                // a wide gap inside a TJ array is a word space
                if ($pending !== [] && (float)$num < -200) {
                    $pending[] = ' ';
                }
                $i += max(1, strlen($num));
                continue;
            }
            if (ctype_alpha($c) || $c === "'" || $c === '"' || $c === '*') {
                preg_match('/\G[A-Za-z\'"*]+/', $s, $m, 0, $i);
                $op = $m[0] ?? $c;
                $i += strlen($op);
                if ($op === 'BT') {
                    $inText = true;
                } elseif ($op === 'ET') {
                    $inText = false;
                    $lines[] = $line;
                    $size += strlen($line) + 1;
                    $line = '';
                } elseif ($inText && in_array($op, ['Tj', 'TJ', "'", '"'], true)) {
                    if ($op !== 'Tj' && $op !== 'TJ') {
                        $lines[] = $line;
                        $size += strlen($line) + 1;
                        $line = '';
                    }
                    $line .= implode('', $pending);
                } elseif ($inText && in_array($op, ['Td', 'TD', 'T*', 'Tm'], true) && $line !== '') {
                    $line .= (str_ends_with($line, ' ') ? '' : ' ');
                }
                $pending = [];
                continue;
            }
            $i++;
        }
        $lines[] = $line;
        $text = implode("\n", array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
        return $text;
    }

    /**
     * A literal string from $i (at its "("): nested parentheses and the
     * escapes of the PDF spec. UTF-16BE with its BOM is converted.
     *
     * @return array{0: string, 1: int} the string and the index after it
     */
    private static function pdfString(string $s, int $i): array {
        $len = strlen($s);
        $depth = 0;
        $out = '';
        for ($i++; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\') {
                $n = $s[$i + 1] ?? '';
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => '', 'f' => '', '(' => '(', ')' => ')', '\\' => '\\'];
                if (isset($map[$n])) {
                    $out .= $map[$n];
                    $i++;
                } elseif (preg_match('/\G[0-7]{1,3}/', $s, $m, 0, $i + 1) === 1) {
                    $out .= chr(octdec($m[0]) & 0xFF);
                    $i += strlen($m[0]);
                } elseif ($n === "\n" || $n === "\r") {
                    $i++;
                }
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
            $out .= $c;
        }
        if (str_starts_with($out, "\xFE\xFF")) {
            $out = (string)mb_convert_encoding(substr($out, 2), 'UTF-8', 'UTF-16BE');
        } else {
            $out = self::utf8($out);
        }
        return [$out, $i + 1];
    }
}
