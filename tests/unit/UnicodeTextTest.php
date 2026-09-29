<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Player;
use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\TallyService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unsichtbares in Wörtern und Namen (TallyService::cleanText).
 *
 * Handys schicken gern Nullbreiten-Leerzeichen, NBSP oder ein „é" in zwei
 * Zeichen (macOS, NFD). Ohne Bereinigung wären „Kaffee" und „Kaffee\u{200B}"
 * zwei Wörter der Wolke und „Anna" stünde zweimal in der Rangliste — optisch
 * gleich, technisch verschieden.
 *
 * Entfernt wird eine feste Liste (weiches Trennzeichen, Nullbreiten-Leerzeichen,
 * Richtungs-Steuerzeichen, Wortverbinder, BOM), NICHT alles aus \p{Cf}. Im
 * Wortinneren bleiben: U+200C (ZWNJ, Persisch „می‌خواهم"), U+200D (ZWJ, hält
 * Emoji wie 👩‍💻 zusammen) und die Tag-Zeichen U+E0020–E007F (Regionsflaggen
 * wie Schottland/England — ohne sie wären beide nur die schwarze Flagge 🏴).
 * An den Rändern fallen ZWNJ/ZWJ wie Leerraum.
 */
#[CoversClass(TallyService::class)]
#[CoversClass(VoteService::class)]
class UnicodeTextTest extends TestCase {

    /** Persisch „ich will": ZWNJ zwischen Präfix und Stamm. */
    private const PERSISCH = "\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}";
    /** 🏴 + Tag-Folge „gbsct" + Abschluss. */
    private const SCHOTTLAND = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";
    /** 🏴 + Tag-Folge „gbeng" + Abschluss. */
    private const ENGLAND = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";

    private PlayerMapper&MockObject $players;
    private VoteService $service;

    protected function setUp(): void {
        $this->players = $this->createMock(PlayerMapper::class);
        $this->players->method('findByRoom')->willReturn([$this->player('tok-anna', 'Anna')]);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        foreach ([
            'playerMapper' => $this->players,
            'timeFactory' => $time,
            'l10n' => $l10n,
        ] as $name => $value) {
            (new ReflectionProperty(VoteService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── cleanText ──────────────────────────────────────────────────────────

    public static function bereinigteTexte(): array {
        return [
            'Nullbreite am Ende' => ["Kaffee\u{200B}", 'Kaffee'],
            'Nullbreite mittendrin' => ["Kaf\u{200B}fee", 'Kaffee'],
            'BOM vorn' => ["\u{FEFF}Kaffee", 'Kaffee'],
            'Richtungszeichen' => ["\u{200F}Kaffee\u{200E}", 'Kaffee'],
            'Richtungs-Einbettung' => ["\u{202B}Kaffee\u{202C}", 'Kaffee'],
            'Richtungs-Isolat' => ["\u{2067}Kaffee\u{2069}", 'Kaffee'],
            'weiches Trennzeichen' => ["Kaf\u{00AD}fee", 'Kaffee'],
            'Wortverbinder' => ["Kaf\u{2060}fee", 'Kaffee'],
            'unsichtbares Mal' => ["Kaf\u{2062}fee", 'Kaffee'],
            'mongolischer Vokaltrenner' => ["\u{180E}Kaffee", 'Kaffee'],
            'NBSP an den Rändern' => ["\u{00A0}Kaffee\u{00A0}", 'Kaffee'],
            'ideografisches Leerzeichen' => ["\u{3000}コーヒー\u{3000}", 'コーヒー'],
            'schmales NBSP' => ["\u{202F}Tee\u{2009}", 'Tee'],
            'NBSP mittendrin bleibt' => ["Café\u{00A0}Latte", "Café\u{00A0}Latte"],
            'NFD wird NFC' => ["Cafe\u{0301}", "Caf\u{00E9}"],
            'Emoji mit Verbinder bleibt' => ["\u{1F469}\u{200D}\u{1F4BB}", "\u{1F469}\u{200D}\u{1F4BB}"],
            'Verbinder am Rand fällt' => ["\u{200D}Tee\u{200D}", 'Tee'],
            'ZWNJ im persischen Wort bleibt' => [self::PERSISCH, self::PERSISCH],
            'ZWNJ am Rand fällt' => ["\u{200C}" . self::PERSISCH . "\u{200C}", self::PERSISCH],
            'Schottland-Flagge bleibt ganz' => [self::SCHOTTLAND, self::SCHOTTLAND],
            'Flagge mit Leerraum drumherum' => ["\u{00A0}" . self::ENGLAND . "\u{200B}", self::ENGLAND],
            'nur Unsichtbares' => ["\u{200B}\u{00A0}\u{FEFF}\u{3000}\u{200D}", ''],
            'normaler Text unverändert' => ['Grüße, Welt!', 'Grüße, Welt!'],
        ];
    }

    #[DataProvider('bereinigteTexte')]
    public function testCleanText(string $in, string $out): void {
        $this->assertSame($out, TallyService::cleanText($in));
    }

    public function testNormalizeWordVereintNfdUndNfc(): void {
        $this->assertSame("caf\u{00E9}", TallyService::normalizeWord("CAFE\u{0301}"));
        $this->assertSame(TallyService::normalizeWord("caf\u{00E9}"), TallyService::normalizeWord("Cafe\u{0301}\u{200B}"));
    }

    public function testNormalizeWordMitUnsichtbaremRand(): void {
        $this->assertSame('kaffee', TallyService::normalizeWord("\u{3000}KAFFEE\u{00A0}\u{200B}"));
    }

    // ── Wortwolke: Stimmprüfung ────────────────────────────────────────────

    public function testNurUnsichtbaresIstKeinWort(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please enter at least one word.');
        $this->service->normalizeValue($this->wordsPoll(3), ["\u{200B}", "\u{00A0}", "\u{FEFF}\u{200D}"]);
    }

    public function testUnsichtbareVariantenSindEinWort(): void {
        $words = $this->service->normalizeValue($this->wordsPoll(3), ['Kaffee', "Kaffee\u{200B}", "\u{00A0}KAFFEE"]);

        $this->assertSame(['Kaffee'], $words);
    }

    public function testGespeichertWirdDieBereinigteForm(): void {
        $words = $this->service->normalizeValue($this->wordsPoll(3), ["\u{200B}Cafe\u{0301}\u{3000}"]);

        $this->assertSame(["Caf\u{00E9}"], $words);
    }

    public function testEmojiMitVerbinderBleibtEinWort(): void {
        $words = $this->service->normalizeValue($this->wordsPoll(3), ["\u{1F469}\u{200D}\u{1F4BB}"]);

        $this->assertSame(["\u{1F469}\u{200D}\u{1F4BB}"], $words);
    }

    public function testPersischesWortMitZwnjBleibtEinWort(): void {
        // Ohne ZWNJ wäre es ein anderes (falsch geschriebenes) Wort.
        $words = $this->service->normalizeValue($this->wordsPoll(3), [self::PERSISCH, "\u{200B}" . self::PERSISCH]);

        $this->assertSame([self::PERSISCH], $words);
        $this->assertStringContainsString("\u{200C}", $words[0]);
    }

    public function testSchottlandFlaggeBleibtEinWort(): void {
        $words = $this->service->normalizeValue($this->wordsPoll(3), [self::SCHOTTLAND]);

        $this->assertSame([self::SCHOTTLAND], $words);
    }

    public function testEnglandUndSchottlandSindZweiWoerter(): void {
        // Mit \p{Cf} blieb von beiden nur 🏴 — die Wolke hätte sie vereint.
        $this->assertNotSame(TallyService::normalizeWord(self::ENGLAND), TallyService::normalizeWord(self::SCHOTTLAND));

        $words = $this->service->normalizeValue($this->wordsPoll(3), [self::ENGLAND, self::SCHOTTLAND]);

        $this->assertSame([self::ENGLAND, self::SCHOTTLAND], $words);
    }

    public function testNichtSkalareWerteWerdenUebersprungen(): void {
        $words = $this->service->normalizeValue($this->wordsPoll(3), [['Kaffee'], null, 'Tee', ['x' => 'y']]);

        $this->assertSame(['Tee'], $words);
    }

    public function testKuerzungEndetNichtAufLeerraum(): void {
        // Zeichen 40 ist ein Leerzeichen — nach dem Schnitt wird erneut bereinigt.
        $word = str_repeat('x', 39) . ' Rest';
        $words = $this->service->normalizeValue($this->wordsPoll(3), [$word]);

        $this->assertSame([str_repeat('x', 39)], $words);
    }

    public function testKuerzungEndetNichtAufNbsp(): void {
        $word = str_repeat('x', 39) . "\u{00A0}Rest";
        $words = $this->service->normalizeValue($this->wordsPoll(3), [$word]);

        $this->assertSame([str_repeat('x', 39)], $words);
    }

    // ── Spielernamen ───────────────────────────────────────────────────────

    public static function vergebeneNamenMitUnsichtbarem(): array {
        return [
            'Nullbreite hinten' => ["Anna\u{200B}"],
            'Nullbreite mittendrin' => ["An\u{200B}na"],
            'NBSP-Rand' => ["\u{00A0}Anna\u{00A0}"],
            'U+3000-Rand' => ["\u{3000}anna"],
            'BOM' => ["\u{FEFF}ANNA"],
            'Variantenwähler' => ["Anna\u{FE0F}"],
            'Text-Variantenwähler' => ["ANNA\u{FE0E}"],
            'Graphem-Verbinder' => ["An\u{034F}na"],
            'Braille-Leerfeld' => ["Anna\u{2800}"],
            'Sprach-Tag' => ["\u{E0001}Anna"],
        ];
    }

    #[DataProvider('vergebeneNamenMitUnsichtbarem')]
    public function testUnsichtbarVarianteEinesVergebenenNamensWirdAbgelehnt(string $name): void {
        $this->players->expects($this->never())->method('register');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This name is already taken. Please choose another one.');
        $this->service->quizJoin($this->room(), 'tok-neu', $name);
    }

    public function testAltbestandMitUnsichtbaremNamenKollidiert(): void {
        // Ein Name von vor der Bereinigung („Ben\u{200B}") belegt „Ben".
        $players = $this->createMock(PlayerMapper::class);
        $players->method('findByRoom')->willReturn([$this->player('tok-ben', "Ben\u{200B}")]);
        $players->expects($this->never())->method('register');
        (new ReflectionProperty(VoteService::class, 'playerMapper'))->setValue($this->service, $players);

        $this->expectExceptionMessage('This name is already taken. Please choose another one.');
        $this->service->quizJoin($this->room(), 'tok-neu', 'ben');
    }

    public function testNullbreiteZwischenLeerzeichenGibtEinLeerzeichen(): void {
        // Erst Unsichtbares raus, dann Leerraum zusammenfassen — sonst bliebe
        // ein doppeltes Leerzeichen, das im Browser wie eines aussieht.
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', 'Anna Lena', 1000)
            ->willReturn($this->player('tok-neu', 'Anna Lena'));

        $this->service->quizJoin($this->room(), 'tok-neu', "Anna \u{200B} Lena");
    }

    public function testAltbestandMitDoppeltemLeerzeichenKollidiert(): void {
        $players = $this->createMock(PlayerMapper::class);
        $players->method('findByRoom')->willReturn([$this->player('tok-al', 'Anna  Lena')]);
        $players->expects($this->never())->method('register');
        (new ReflectionProperty(VoteService::class, 'playerMapper'))->setValue($this->service, $players);

        $this->expectExceptionMessage('This name is already taken. Please choose another one.');
        $this->service->quizJoin($this->room(), 'tok-neu', 'anna lena');
    }

    public function testVerbinderVorAkzentGibtDenselbenNamen(): void {
        // Der Verbinder blockiert NFC; ohne ihn muss „René" herauskommen.
        $this->assertSame(TallyService::nameKey("Ren\u{00E9}"), TallyService::nameKey("Rene\u{034F}\u{0301}"));
        $this->assertSame(TallyService::wordKey("Caf\u{00E9}"), TallyService::wordKey("Cafe\u{FE0F}\u{0301}"));
    }

    public function testNameNurAusVariantenwaehlernWirdAbgelehnt(): void {
        $this->players->expects($this->never())->method('register');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please enter a name.');
        $this->service->quizJoin($this->room(), 'tok-neu', "\u{FE0F}\u{034F}");
    }

    public function testNameNurAusBrailleLeerfeldWirdAbgelehnt(): void {
        $this->players->expects($this->never())->method('register');

        $this->expectExceptionMessage('Please enter a name.');
        $this->service->quizJoin($this->room(), 'tok-neu', "\u{2800}\u{2800}");
    }

    public function testWortAusVariantenwaehlerUndVerbinderIstKeinWort(): void {
        $words = $this->service->normalizeValue($this->wordsPoll(1), ["\u{FE0F}\u{200D}\u{FE0F}", "\u{2800}", 'Kaffee']);

        $this->assertSame(['Kaffee'], $words);
    }

    public function testWortNurAusVariantenwaehlerBelegtKeinenPlatz(): void {
        $words = $this->service->normalizeValue($this->wordsPoll(1), ["\u{034F}", 'Kaffee']);

        $this->assertSame(['Kaffee'], $words);
    }

    public function testNameAusNurUnsichtbaremWirdAbgelehnt(): void {
        $this->players->expects($this->never())->method('register');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please enter a name.');
        $this->service->quizJoin($this->room(), 'tok-neu', "\u{200B}\u{00A0}\u{3000}\u{FEFF}");
    }

    public function testLeerraumArtenWerdenEinLeerzeichen(): void {
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', 'Anna Lena Maria', 1000)
            ->willReturn($this->player('tok-neu', 'Anna Lena Maria'));

        $this->service->quizJoin($this->room(), 'tok-neu', "Anna\u{00A0}\u{00A0}Lena\u{3000}Maria\u{200B}");
    }

    public function testKuerzungAuf24ZeichenEndetNichtAufLeerzeichen(): void {
        // Zeichen 24 ist das Leerzeichen vor dem Nachnamen.
        $first = str_repeat('a', 23);
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', $first, 1000)
            ->willReturn($this->player('tok-neu', $first));

        $this->service->quizJoin($this->room(), 'tok-neu', $first . ' Lena');
    }

    public function testKuerzungZaehltZeichenNichtBytes(): void {
        $name = str_repeat('Ä', 30);
        $this->players->expects($this->once())->method('register')
            ->with(1, 'tok-neu', str_repeat('Ä', 24), 1000)
            ->willReturn($this->player('tok-neu', str_repeat('Ä', 24)));

        $this->service->quizJoin($this->room(), 'tok-neu', $name);
    }

    private function wordsPoll(int $maxWords): Poll {
        $poll = new Poll();
        $poll->setType('words');
        $poll->setMaxWords($maxWords);
        return $poll;
    }

    private function room(): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        return $room;
    }

    private function player(string $token, string $nickname): Player {
        $player = new Player();
        $player->setRoomId(1);
        $player->setVoterToken($token);
        $player->setNickname($nickname);
        return $player;
    }
}
