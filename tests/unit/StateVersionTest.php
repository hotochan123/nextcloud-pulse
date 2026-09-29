<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\VoteService;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Der öffentliche Versions-Fingerabdruck (StateService::stateVersion).
 *
 * Er geht an jedes Handy und an den Beamer. Roh enthielt er crc32(answerKey)
 * und den Stimmen-Stempel (CRC über alle Payloads samt correct/points) — beides
 * ließ sich bei bekannten Options-IDs durchprobieren, also lag die Lösung offen.
 * Jetzt ist er ein Schlüssel-Hash: 24 Hex-Zeichen, nur auf Gleichheit
 * vergleichbar. Er muss trotzdem genau dann springen, wenn sich etwas ändert —
 * sonst quittiert der 204-Cache neue Stimmen weg.
 */
#[CoversClass(StateService::class)]
class StateVersionTest extends TestCase {

    private const ANSWER_KEY = '{"order":["AA01","BB02","CC03"]}';

    private StateService $service;
    private Poll $poll;
    private string $stamp = '1234567890';
    private int $present = 3;
    private int $players = 2;
    private bool $practice = false;
    private string $title = 'Raum';
    private string $secret = 'geheim-der-instanz';

    protected function setUp(): void {
        $this->poll = new Poll();
        $this->poll->setId(7);
        $this->poll->setRoomId(1);
        $this->poll->setType('rank');
        $this->poll->setStatus('active');
        $this->poll->setStartedAt(900);
        $this->poll->setAnswerKey(self::ANSWER_KEY);

        $polls = $this->createMock(PollMapper::class);
        $polls->method('find')->willReturnCallback(fn (): Poll => $this->poll);

        $votes = $this->createMock(VoteMapper::class);
        $votes->method('changeStamp')->willReturnCallback(fn (): string => $this->stamp);

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('presentCount')->willReturnCallback(fn (): int => $this->present);

        $voteService = $this->createMock(VoteService::class);
        $voteService->method('playerCount')->willReturnCallback(fn (): int => $this->players);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturnCallback(
            fn (string $key, string $default = ''): string => $key === 'secret' ? $this->secret : $default,
        );

        $this->service = (new \ReflectionClass(StateService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $polls,
            'voteMapper' => $votes,
            'roomService' => $roomService,
            'voteService' => $voteService,
            'config' => $config,
        ] as $name => $value) {
            (new ReflectionProperty(StateService::class, $name))->setValue($this->service, $value);
        }
    }

    public function testVersionIst24HexZeichen(): void {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $this->version());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $this->version(active: 0), 'auch in der Lobby');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $this->version(presence: true), 'auch mit Präsenz');
    }

    public function testGleicherZustandGibtGleicheVersion(): void {
        $this->assertSame($this->version(), $this->version());
        $this->assertSame($this->version(active: 0), $this->version(active: 0));
    }

    public function testGeaenderteStimmeAendertDieVersion(): void {
        // Upsert: Anzahl gleich, Payload anders -> anderer Stempel.
        $before = $this->version();
        $this->stamp = '987654321';

        $this->assertNotSame($before, $this->version());
    }

    public function testStatusStartzeitUndLoesungAendernDieVersion(): void {
        $base = $this->version();

        $this->poll->setStatus('locked');
        $locked = $this->version();
        $this->assertNotSame($base, $locked, 'Auflösen');

        $this->poll->setStartedAt(950);
        $restarted = $this->version();
        $this->assertNotSame($locked, $restarted, 'Timer neu gestartet');

        $this->poll->setAnswerKey('{"accepted":["ja"],"rejected":[]}');
        $this->assertNotSame($restarted, $this->version(), 'Freitext bewertet (answerKey)');
    }

    public function testPraesenzNurMitSchalter(): void {
        $without = $this->version();
        $with = $this->version(presence: true);
        $this->present = 4;

        $this->assertSame($without, $this->version(), 'Abstimmende pollen nicht wegen Heartbeats neu');
        $this->assertNotSame($with, $this->version(presence: true), 'Zuschauer-Ansicht sieht neue Beitritte');
    }

    public function testLobbyZaehltAnwesende(): void {
        $before = $this->version(active: 0);
        $this->present = 9;

        $this->assertNotSame($before, $this->version(active: 0));
    }

    public function testEnthaeltKeinenRohenFingerabdruck(): void {
        $version = $this->version();
        $raw = '7:active:900:' . $this->stamp . ':' . crc32(self::ANSWER_KEY);

        $this->assertStringNotContainsString((string)crc32(self::ANSWER_KEY), $version, 'kein CRC über den answerKey');
        $this->assertStringNotContainsString($this->stamp, $version, 'kein Stimmen-Stempel');
        $this->assertStringNotContainsString(':', $version);
        $this->assertNotSame(substr(hash('sha256', $raw), 0, 24), $version, 'kein ungeschlüsselter Hash');
        $this->assertNotSame(substr(hash_hmac('sha256', $raw, 'pulse-state:'), 0, 24), $version,
            'ohne das Instanz-Geheimnis nicht nachrechenbar');
    }

    public function testVersionHaengtAmInstanzGeheimnis(): void {
        // Wer den Schlüssel nicht kennt, kann Kandidaten-Lösungen nicht gegen
        // die Version prüfen.
        $before = $this->version();
        $this->secret = 'anderes-geheimnis';

        $this->assertNotSame($before, $this->version());
    }

    public function testAufloesungAmEndeUmschaltenAendertDieVersion(): void {
        // Gesperrte Frage, danach „Auflösung am Ende" ein: kein Status ändert
        // sich, aber Handy und Beamer dürfen die Auflösung nicht mehr zeigen.
        $this->poll->setStatus('locked');
        $shown = $this->version();
        $hidden = $this->version(revealAtEnd: true);

        $this->assertNotSame($shown, $hidden, 'ein');
        $this->assertSame($shown, $this->version(), 'aus: wieder wie vorher');
        $this->assertNotSame($this->version(presence: true), $this->version(presence: true, revealAtEnd: true), 'auch für den Beamer');
    }

    public function testAufloesungAmEndeBeiLaufenderFrageAendertNichts(): void {
        // Laufende Frage: verdeckt so oder so — kein Anlass für volle Polls.
        $this->assertSame($this->version(), $this->version(revealAtEnd: true));
    }

    public function testProbelaufAendertDieVersion(): void {
        $this->poll->setStatus('locked');

        $this->assertNotSame($this->version(), $this->version(practice: true));
    }

    public function testLobbySiehtZuruecksetzenProbelaufUndTitel(): void {
        // Zurücksetzen/Probelauf-Schalter löschen alle Spielenden; der eigene
        // Heartbeat bringt die Anwesenheit sofort wieder auf den alten Wert.
        $base = $this->version(active: 0);

        $this->players = 0;
        $reset = $this->version(active: 0);
        $this->assertNotSame($base, $reset, 'Spielende gelöscht');

        $this->practice = true;
        $practice = $this->version(active: 0);
        $this->assertNotSame($reset, $practice, 'Probelauf-Banner');

        $this->title = 'Neuer Titel';
        $this->assertNotSame($practice, $this->version(active: 0), 'Titel');
    }

    public function testBearbeiteteFrageOhneStimmenAendertDieVersion(): void {
        // Umfrage: Bearbeiten ändert weder Status noch Startzeit noch Stempel.
        $before = $this->version();
        $this->poll->setQuestion('Neu formuliert?');

        $this->assertNotSame($before, $this->version());
    }

    private function version(int $active = 7, bool $presence = false, bool $revealAtEnd = false, bool $practice = false): string {
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        $room->setActivePollId($active);
        $room->setRevealAtEnd($revealAtEnd);
        $room->setPractice($practice || $this->practice);
        $room->setTitle($this->title);
        return $this->service->stateVersion($room, $presence);
    }
}
