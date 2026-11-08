<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Service\SourceText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The text of a picked file for the AI quiz draft: every supported type
 * gives its text (files built here, no fixtures), unsupported types are
 * named, and hostile files (external entities, zip bombs, broken streams)
 * give no more than their plain text.
 */
#[CoversClass(SourceText::class)]
class SourceTextTest extends TestCase {

    /** @param array<string, string> $entries */
    private static function zip(array $entries): string {
        $tmp = tempnam(sys_get_temp_dir(), 'pulse-test');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string)file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    private static function pdf(string ...$streams): string {
        $out = "%PDF-1.4\n";
        foreach ($streams as $i => $s) {
            $out .= ($i + 1) . " 0 obj\n<< " . (str_starts_with($s, "\x78") ? '/Filter /FlateDecode ' : '') . '/Length ' . strlen($s) . " >>\nstream\n" . $s . "\nendstream\nendobj\n";
        }
        return $out . "%%EOF\n";
    }

    public function testPlainTextAndMarkdown(): void {
        $this->assertSame("Line one\nLine two", SourceText::extract('a.txt', 'text/plain', "Line one\nLine two"));
        $this->assertSame('# Title', SourceText::extract('a.md', 'text/markdown', "\u{FEFF}# Title"));
        $this->assertSame('x', SourceText::extract('noext', 'text/plain', 'x'));
    }

    public function testWindows1252TextBecomesUtf8(): void {
        $this->assertSame('Grüße – Köln', SourceText::extract('a.txt', 'text/plain', "Gr\xFC\xDFe \x96 K\xF6ln"));
    }

    public function testDocx(): void {
        $xml = '<?xml version="1.0"?><w:document xmlns:w="w"><w:body>'
            . '<w:p><w:r><w:t>Die Photosynthese</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t xml:space="preserve">Pflanzen &amp; Licht </w:t></w:r><w:r><w:tab/><w:t>Zucker</w:t></w:r></w:p>'
            . '</w:body></w:document>';
        $text = SourceText::extract('Bio.DOCX', '', self::zip(['word/document.xml' => $xml]));
        $this->assertSame("Die Photosynthese\nPflanzen & Licht Zucker", $text);
    }

    public function testPptxSlidesInSlideOrder(): void {
        $slide = fn (string $t) => '<p:sld xmlns:a="a" xmlns:p="p"><a:p><a:r><a:t>' . $t . '</a:t></a:r></a:p></p:sld>';
        $text = SourceText::extract('deck.pptx', '', self::zip([
            'ppt/slides/slide10.xml' => $slide('Ten'),
            'ppt/slides/slide2.xml' => $slide('Two'),
            'ppt/slides/slide1.xml' => $slide('One'),
            'ppt/notesSlides/notesSlide1.xml' => $slide('Speaker notes'),
        ]));
        $this->assertSame("One\n\nTwo\n\nTen", $text);
    }

    public function testOdtAndOdp(): void {
        $xml = '<office:document-content xmlns:office="o" xmlns:text="t"><office:body>'
            . '<text:h>Vulkane</text:h><text:p>Magma<text:s/>steigt<text:line-break/>auf</text:p></office:body></office:document-content>';
        $this->assertSame("Vulkane\nMagma steigt\nauf", SourceText::extract('a.odt', '', self::zip(['content.xml' => $xml])));
        $this->assertSame("Vulkane\nMagma steigt\nauf", SourceText::extract('a.odp', '', self::zip(['content.xml' => $xml])));
    }

    public function testExternalEntityIsNeverResolved(): void {
        $secret = tempnam(sys_get_temp_dir(), 'pulse-secret');
        file_put_contents($secret, 'TOP-SECRET');
        $xml = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
            . '<w:document xmlns:w="w"><w:body><w:p><w:t>Hello &xxe;</w:t></w:p></w:body></w:document>';
        $text = (string)SourceText::extract('x.docx', '', self::zip(['word/document.xml' => $xml]));
        unlink($secret);
        $this->assertStringNotContainsString('TOP-SECRET', $text);
        $this->assertStringContainsString('Hello', $text);
    }

    public function testOversizedZipEntryIsSkipped(): void {
        $big = '<w:p><w:t>' . str_repeat('a', SourceText::MAX_PART_BYTES) . '</w:t></w:p>';
        $this->assertSame('', SourceText::extract('bomb.docx', '', self::zip(['word/document.xml' => $big])));
    }

    public function testBrokenZipGivesNoText(): void {
        $this->assertSame('', SourceText::extract('broken.pptx', '', 'PK not really a zip'));
    }

    public function testPdfFlateAndPlainStreamsWithTjAndTJ(): void {
        $page1 = gzcompress("BT /F1 12 Tf 72 700 Td (Der Wasserkreislauf) Tj 0 -14 Td [(Ver) 20 (dunstung) -400 (und Regen)] TJ ET");
        $page2 = "BT (Wolken \\(Kondensation\\)) Tj T* (Stra\\337e) ' ET";
        $text = SourceText::extract('script.pdf', 'application/pdf', self::pdf($page1, $page2));
        $this->assertSame("Der Wasserkreislauf Verdunstung und Regen\nWolken (Kondensation)\nStraße", $text);
    }

    public function testPdfUtf16String(): void {
        $s = "\xFE\xFF" . mb_convert_encoding('Ökosystem', 'UTF-16BE', 'UTF-8');
        $text = SourceText::extract('a.pdf', '', self::pdf('BT (' . addcslashes($s, '()\\') . ') Tj ET'));
        $this->assertSame('Ökosystem', $text);
    }

    public function testPdfImageFontsAndBrokenStreamsGiveNoText(): void {
        $pdf = "%PDF-1.4\n1 0 obj << /Subtype /Image /Length 5 >> stream\nBT (img) Tj ET\nendstream endobj\n"
            . "2 0 obj << /Filter /FlateDecode /Length 4 >> stream\nxxxx\nendstream endobj\n"
            . "3 0 obj << /Filter /DCTDecode >> stream\nBT (jpeg) Tj ET\nendstream endobj\n"
            . "4 0 obj << /Length 9 >> stream\nBT <0041> Tj ET\nendstream endobj\n%%EOF";
        $this->assertSame('', SourceText::extract('scan.pdf', '', $pdf));
    }

    public function testSmallPdfOfManyCompressedStreamsStopsAtTheTextLimit(): void {
        // ≈ 10 MB of text per stream, 24 KB packed: 40 of them were seconds of work and hundreds of MB of text
        $z = gzcompress(str_repeat("BT (Lorem ipsum dolor) Tj ET\n", 350000), 9);
        $pdf = self::pdf(...array_fill(0, 40, $z));
        $this->assertLessThan(2 * 1024 * 1024, strlen($pdf));

        $t = microtime(true);
        $text = (string)SourceText::extract('bomb.pdf', '', $pdf);

        $this->assertLessThan(3.0, microtime(true) - $t, 'reading stops early');
        $this->assertLessThanOrEqual(SourceText::TEXT_MAX_BYTES, strlen($text));
        $this->assertGreaterThan(SourceText::TEXT_MAX_BYTES - 100, strlen($text), 'what is read is kept');
        $this->assertStringStartsWith('Lorem ipsum dolor', $text);
    }

    public function testPdfStopsAfterTheUnpackedLimitWithoutText(): void {
        // streams that unpack to 8 MB each but hold no text: reading ends after UNPACKED_MAX_BYTES
        $empty = gzcompress(str_repeat(' ', 8 * 1024 * 1024), 9);
        $text = gzcompress('BT (late text) Tj ET');
        $streams = array_fill(0, (int)(SourceText::UNPACKED_MAX_BYTES / (8 * 1024 * 1024)) + 2, $empty);
        $streams[] = $text;
        $this->assertSame('', SourceText::extract('a.pdf', '', self::pdf(...$streams)));
        $this->assertSame('late text', SourceText::extract('b.pdf', '', self::pdf($empty, $text)));
    }

    public function testPptxOfManyLargeSlidesStopsAtTheTextLimit(): void {
        $slide = '<a:p><a:t>' . str_repeat('Folie ', 50000) . '</a:t></a:p>'; // ≈ 300 KB of text each
        $entries = [];
        for ($i = 1; $i <= 40; $i++) {
            $entries["ppt/slides/slide$i.xml"] = $slide;
        }
        $text = (string)SourceText::extract('big.pptx', '', self::zip($entries));
        $this->assertLessThanOrEqual(SourceText::TEXT_MAX_BYTES, strlen($text));
        $this->assertStringStartsWith('Folie Folie', $text);
    }

    public function testUnsupportedTypes(): void {
        foreach (['a.jpg', 'a.doc', 'a.ppt', 'a.xlsx', 'a.zip', 'noext'] as $name) {
            $this->assertNull(SourceText::extract($name, 'application/octet-stream', 'data'), $name);
        }
    }
}
