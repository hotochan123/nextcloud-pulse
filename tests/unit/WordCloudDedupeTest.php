<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Service\TallyService;
use OCA\Pulse\Service\VoteService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Word cloud: "Kaffee" and "KAFFEE" from one person are ONE word.
 *
 * Two places must use the same normal form (TallyService::normalizeWord):
 * vote validation (VoteService::normalizeValue), which does not even store
 * duplicates, and the tally, which heals old votes from before the validation.
 */
#[CoversClass(VoteService::class)]
#[CoversClass(TallyService::class)]
class WordCloudDedupeTest extends TestCase {

    private VoteService $service;

    protected function setUp(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        // normalizeValue touches no mapper for words — only l10n.
        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(VoteService::class, 'l10n'))->setValue($this->service, $l10n);
    }

    // ── normalizeWord: the ONE source ──────────────────────────────────────

    public function testNormalizeWordTrimmtUndSchreibtKlein(): void {
        $this->assertSame('kaffee', TallyService::normalizeWord('  KAFFEE '));
    }

    public function testNormalizeWordIstMehrbyteSicher(): void {
        $this->assertSame('äpfel über', TallyService::normalizeWord('ÄPFEL ÜBER'));
    }

    // ── Vote validation ────────────────────────────────────────────────────

    public function testGrossKleinVariantenWerdenEinWortErsteSchreibweiseBleibt(): void {
        $words = $this->service->normalizeValue($this->poll(3), ['Kaffee', 'KAFFEE', ' kaffee ']);

        $this->assertSame(['Kaffee'], $words, 'ein Eintrag, gespeichert wie zuerst geschrieben');
    }

    public function testErsteSchreibweiseAuchWennSieGrossGeschriebenIst(): void {
        $words = $this->service->normalizeValue($this->poll(3), ['TEE', 'Tee', 'Kaffee']);

        $this->assertSame(['TEE', 'Kaffee'], $words, 'Reihenfolge der Erstnennung bleibt');
    }

    public function testObergrenzeGreiftErstNachDemEntdoppeln(): void {
        // Four inputs, but only three distinct words — with maxWords 2
        // the duplicates must not take up a slot.
        $words = $this->service->normalizeValue($this->poll(2), ['Kaffee', 'KAFFEE', 'Tee', 'Wasser']);

        $this->assertSame(['Kaffee', 'Tee'], $words);
    }

    public function testZahlwortBleibtEinString(): void {
        // As an array key "42" becomes an integer — but what has to be stored
        // is still the text, otherwise the tally (strings only) does not count it.
        $words = $this->service->normalizeValue($this->poll(3), ['42', ' 42', 7]);

        $this->assertSame(['42', '7'], $words);
    }

    public function testEinzelnerStringWirdZurListe(): void {
        $this->assertSame(['Kaffee'], $this->service->normalizeValue($this->poll(3), '  Kaffee  '));
    }

    public function testNurLeereEingabenWerdenAbgelehnt(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please enter at least one word.');
        $this->service->normalizeValue($this->poll(3), ['', '   ']);
    }

    public function testKuerzungVorDemVergleich(): void {
        // Two long inputs that only differ after character 40 end up truncated
        // as the same word in the cloud — so one entry.
        $a = str_repeat('x', 40) . 'A';
        $b = str_repeat('X', 40) . 'B';
        $words = $this->service->normalizeValue($this->poll(3), [$a, $b]);

        $this->assertSame([str_repeat('x', 40)], $words);
    }

    // ── Tally: healing old votes ───────────────────────────────────────────

    public function testAltstimmeMitDoppeltenZaehltJeWortNurEinmal(): void {
        // Stored before the validation existed: one person with three spellings of
        // Kaffee and Tee twice, plus a second person with Kaffee.
        $tally = (new TallyService())->tally($this->poll(5), [
            $this->vote(['Kaffee', 'KAFFEE', ' kaffee', 'Tee', 'TEE']),
            $this->vote(['kaffee']),
        ]);

        $this->assertSame(2, $tally['total']);
        $this->assertSame([
            ['word' => 'kaffee', 'count' => 2],
            ['word' => 'tee', 'count' => 1],
        ], $tally['results']);
    }

    public function testZahlwortKommtAlsTextAusDerAuszaehlung(): void {
        $tally = (new TallyService())->tally($this->poll(3), [$this->vote(['42']), $this->vote(['42'])]);

        $this->assertSame([['word' => '42', 'count' => 2]], $tally['results']);
    }

    // ── Word key: variation selectors, whitespace ──────────────────────────

    public function testVariantenwaehlerMachtKeinNeuesWort(): void {
        $words = $this->service->normalizeValue($this->poll(3), ["\u{2764}\u{FE0F}", "\u{2764}", "\u{2764}\u{FE0E}"]);

        $this->assertSame(["\u{2764}\u{FE0F}"], $words, 'ein Eintrag, bunte Schreibweise bleibt gespeichert');
    }

    public function testDoppelterLeerraumMachtKeinNeuesWort(): void {
        $words = $this->service->normalizeValue($this->poll(3), ['guter  Kaffee', 'guter Kaffee']);

        $this->assertSame(['guter  Kaffee'], $words);
    }

    public function testAuszaehlungZeigtDieErsteSchreibweise(): void {
        $tally = (new TallyService())->tally($this->poll(3), [
            $this->vote(["\u{2764}\u{FE0F}", 'guter  Kaffee']),
            $this->vote(["\u{2764}", 'Guter Kaffee']),
            $this->vote(["\u{2764}\u{FE0E}"]),
        ]);

        $this->assertSame([
            ['word' => "\u{2764}\u{FE0F}", 'count' => 3],
            ['word' => 'guter kaffee', 'count' => 2],
        ], $tally['results']);
    }

    public function testWortschluesselLaesstPersischUndEmojiVerbinderStehen(): void {
        // ZWNJ/ZWJ change the appearance — unlike with names, they stay in the key.
        $this->assertNotSame(TallyService::wordKey("\u{0645}\u{06CC}\u{200C}\u{062E}"), TallyService::wordKey("\u{0645}\u{06CC}\u{062E}"));
        $this->assertNotSame(TallyService::wordKey("\u{1F469}\u{200D}\u{1F4BB}"), TallyService::wordKey("\u{1F469}\u{1F4BB}"));
    }

    private function poll(int $maxWords): Poll {
        $poll = new Poll();
        $poll->setType('words');
        $poll->setMaxWords($maxWords);
        return $poll;
    }

    private function vote(mixed $value): Vote {
        $vote = new Vote();
        $vote->setPayload(json_encode(['value' => $value]));
        return $vote;
    }
}
