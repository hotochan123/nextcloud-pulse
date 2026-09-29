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
use OCA\Pulse\Service\TallyService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Was die öffentlichen Sichten (Beamer, Handy) vorab NICHT verraten dürfen.
 *
 * 1. Reihenfolge/Zuordnung im Quiz: die gespeicherte Optionsfolge IST die
 *    Lösung (bzw. Ziel i gehört zu Item i). Bis zum Auflösen kommt sie
 *    gemischt heraus — geordnet nach einem Schlüssel-Hash über Server-
 *    Geheimnis, Frage-ID und Options-ID. Damit ist die Folge für alle
 *    Betrachter gleich und hängt nicht an der gespeicherten Folge.
 * 2. Gesamtauswertung `/s/{code}/summary`: nie gezeigte Fragen fehlen ganz,
 *    sonst läge das restliche Deck samt Antwortmöglichkeiten auf jedem Handy.
 *
 * Geprüft über die öffentlichen Einstiege publicState/publicSummary — genau
 * das, was die Controller ausliefern. Auszählung ist echt (TallyService),
 * die Mapper sind gedoubelt; es gibt keine Stimmen.
 */
#[CoversClass(StateService::class)]
class PublicSpoilerTest extends TestCase {

    /** Gespeicherte Folge = Lösung. */
    private const RANK = [
        ['id' => 'ZZ9A', 'label' => 'Erster'],
        ['id' => 'AB12', 'label' => 'Zweiter'],
        ['id' => 'M3K0', 'label' => 'Dritter'],
    ];

    private PollMapper&MockObject $polls;
    private StateService $service;
    private string $secret = 'test-secret';

    protected function setUp(): void {
        $this->polls = $this->createMock(PollMapper::class);

        $votes = $this->createMock(VoteMapper::class);
        $votes->method('findByPoll')->willReturn([]);
        $votes->method('findByPollAndToken')->willThrowException(new DoesNotExistException('keine Stimme'));
        $votes->method('countByPoll')->willReturn(0);

        $voteService = $this->createMock(VoteService::class);
        $voteService->method('leaderboardFor')->willReturn([]);
        $voteService->method('playerNickname')->willReturn(null);

        $roomService = $this->createMock(RoomService::class);
        $roomService->method('presentCount')->willReturn(0);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturnCallback(
            fn (string $key, string $default = ''): string => $key === 'secret' ? $this->secret : $default,
        );

        $this->service = (new \ReflectionClass(StateService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $this->polls,
            'voteMapper' => $votes,
            'tallyService' => new TallyService(),
            'voteService' => $voteService,
            'roomService' => $roomService,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'config' => $config,
        ] as $name => $value) {
            (new ReflectionProperty(StateService::class, $name))->setValue($this->service, $value);
        }
    }

    // ── Reihenfolge: kein Spoiler über die Optionsfolge ────────────────────

    public function testQuizRangfolgeVorDemAufloesenGemischt(): void {
        $state = $this->stateFor($this->room('quiz', active: 7), $this->rankPoll('active'));
        $served = $state['poll']['options'];

        $this->assertFalse($state['poll']['revealed']);
        $this->assertIsPermutation(self::RANK, $served);
        $this->assertSame(self::hashFolge(7, self::RANK, $this->secret), $served);
    }

    public function testQuizRangfolgeNachDemAufloesenInGespeicherterFolge(): void {
        // Je-Frage-Auflösung: 'locked' heißt aufgelöst.
        $state = $this->stateFor($this->room('quiz', active: 7), $this->rankPoll('locked'));

        $this->assertTrue($state['poll']['revealed']);
        $this->assertSame(['ZZ9A', 'AB12', 'M3K0'], array_column($state['poll']['options'], 'id'));
    }

    public function testQuizAufloesungAmEndeBleibtBeiLockedGemischt(): void {
        // „Auflösung am Ende": 'locked' ist nur pausiert, erst 'ended' löst auf.
        $room = $this->room('quiz', active: 7, revealAtEnd: true);

        $locked = $this->stateFor($room, $this->rankPoll('locked'));
        $this->assertSame('locked', $locked['poll']['status']);
        $this->assertFalse($locked['poll']['revealed']);
        $this->assertSame(self::hashFolge(7, self::RANK, $this->secret), $locked['poll']['options']);

        $ended = $this->stateFor($room, $this->rankPoll('ended'));
        $this->assertTrue($ended['poll']['revealed']);
        $this->assertSame(['ZZ9A', 'AB12', 'M3K0'], array_column($ended['poll']['options'], 'id'));
    }

    public function testUmfrageRangfolgeBleibtInGespeicherterFolge(): void {
        // In der Umfrage gibt es keine Lösung — die Folge des Moderators gilt.
        $state = $this->stateFor($this->room('poll', active: 7), $this->rankPoll('active'));

        $this->assertSame(['ZZ9A', 'AB12', 'M3K0'], array_column($state['poll']['options'], 'id'));
    }

    public function testQuizZuordnungMischtNurDieZiele(): void {
        $items = [['id' => 'QQ01', 'label' => 'Hund'], ['id' => 'AA01', 'label' => 'Katze'], ['id' => 'MM01', 'label' => 'Kuh']];
        // Ziel i gehört zu Item i — gespeichert ist also die Lösung.
        $targets = [['id' => 'TC03', 'label' => 'bellt'], ['id' => 'TA01', 'label' => 'miaut'], ['id' => 'TB02', 'label' => 'muht']];
        $poll = $this->poll(7, 'match', 'active', 900, ['items' => $items, 'targets' => $targets]);

        $match = $this->stateFor($this->room('quiz', active: 7), $poll)['poll']['match'];

        $this->assertSame($items, $match['items'], 'Items behalten ihre Folge');
        $this->assertIsPermutation($targets, $match['targets']);
        $this->assertSame(self::hashFolge(7, $targets, $this->secret), $match['targets']);
        $this->assertSame(['TA01', 'TB02', 'TC03'], array_column($match['targets'], 'id'), 'hier nicht die Lösungsfolge');
    }

    public function testQuizZuordnungNachDemAufloesenUnveraendert(): void {
        $poll = $this->poll(7, 'match', 'locked', 900, [
            'items' => [['id' => 'QQ01', 'label' => 'Hund'], ['id' => 'AA01', 'label' => 'Katze']],
            'targets' => [['id' => 'ZT01', 'label' => 'bellt'], ['id' => 'BT01', 'label' => 'miaut']],
        ]);

        $state = $this->stateFor($this->room('quiz', active: 7), $poll);

        $this->assertSame(['ZT01', 'BT01'], array_column($state['poll']['match']['targets'], 'id'));
    }

    public function testMischungIstFuerAlleBetrachterGleich(): void {
        // Beamer und Handys fragen getrennt — die Folge darf nicht springen,
        // weder zwischen zwei Abrufen noch zwischen Tokens noch zwischen
        // Live-Sicht und Gesamtauswertung.
        $room = $this->room('quiz', active: 7);
        $first = $this->stateFor($room, $this->rankPoll('active'))['poll']['options'];

        $this->assertSame($first, $this->stateFor($room, $this->rankPoll('active'))['poll']['options']);
        foreach (['tok-a', 'tok-b', ''] as $token) {
            $this->assertSame($first, $this->stateFor($room, $this->rankPoll('active'), $token)['poll']['options'], 'Token ' . $token);
        }

        // stateFor hat den Mapper getauscht — für die Gesamtauswertung zurück.
        (new ReflectionProperty(StateService::class, 'pollMapper'))->setValue($this->service, $this->polls);
        $this->polls->method('findByRoom')->willReturn([$this->rankPoll('active')]);
        foreach ([null, 'tok-a'] as $token) {
            $this->assertSame($first, $this->service->publicSummary($room, $token)['items'][0]['poll']['options']);
        }
    }

    public function testMischungHaengtAnGeheimnisUndFrage(): void {
        // Dieselben Options-IDs, dieselbe Lösung: eine andere Instanz (anderes
        // Geheimnis) oder eine andere Frage mischt anders. Die IDs sind so
        // gewählt, dass sich die Folgen tatsächlich unterscheiden.
        $room7 = $this->room('quiz', active: 7);
        $room8 = $this->room('quiz', active: 8);

        $this->assertSame(['AB12', 'M3K0', 'ZZ9A'], $this->servedIds($room7, $this->rankPoll('active')));
        $this->assertSame(['M3K0', 'ZZ9A', 'AB12'], $this->servedIds($room8, $this->poll(8, 'rank', 'active', 900, self::RANK)), 'andere Frage');

        $this->secret = 'anderes-geheimnis';
        $this->assertSame(['M3K0', 'ZZ9A', 'AB12'], $this->servedIds($room7, $this->rankPoll('active')), 'anderes Geheimnis');
    }

    // ── Gesamtauswertung: nur gezeigte Fragen ──────────────────────────────

    public function testSummaryLaesstNieGezeigteFragenWeg(): void {
        $room = $this->room('poll', active: 2);
        $this->polls->method('findByRoom')->willReturn([
            $this->poll(1, 'choice', 'active', 900),   // früher gezeigt
            $this->poll(2, 'choice', 'active', 950),   // läuft gerade
            $this->poll(3, 'choice', 'active', 0),     // noch nie dran
        ]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
        $this->assertStringNotContainsString('Frage 3', json_encode($summary), 'kein Text der nächsten Frage');
    }

    public function testSummaryBehaeltAktiveFrageOhneStartzeitpunkt(): void {
        // Umfrage-Raum von vor dem Startzeitpunkt: die laufende Frage hat 0.
        $room = $this->room('poll', active: 3);
        $this->polls->method('findByRoom')->willReturn([$this->poll(3, 'choice', 'active', 0)]);

        $this->assertCount(1, $this->service->publicSummary($room, null)['items']);
    }

    public function testSummaryBehaeltAlteGesperrteUmfragefrage(): void {
        // Altbestand: gesperrt, aber nie mit Startzeitpunkt versehen — gezeigt
        // wurde sie trotzdem (sonst hätte niemand sie sperren können).
        $room = $this->room('poll', active: 0);
        $this->polls->method('findByRoom')->willReturn([
            $this->poll(1, 'choice', 'locked', 0),
            $this->poll(2, 'choice', 'ended', 0),
            $this->poll(3, 'choice', 'active', 0),
        ]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
    }

    public function testQuizSummaryZeigtKeineFolgefragenUndMischtOffeneRangfolge(): void {
        // Quiz je Frage: Frage 1 aufgelöst, Frage 2 läuft, Frage 3 kommt erst.
        $room = $this->room('quiz', active: 2);
        $this->polls->method('findByRoom')->willReturn([
            $this->poll(1, 'choice', 'locked', 900, [['id' => 'AA', 'label' => 'A'], ['id' => 'BB', 'label' => 'B']]),
            $this->poll(2, 'rank', 'active', 950, self::RANK),
            $this->poll(3, 'choice', 'active', 0, [['id' => 'CC', 'label' => 'C'], ['id' => 'DD', 'label' => 'D']]),
        ]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertTrue($summary['available']);
        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
        $this->assertFalse($summary['items'][1]['revealed']);
        $this->assertFalse($summary['items'][1]['poll']['revealed']);
        $this->assertSame(self::hashFolge(2, self::RANK, $this->secret), $summary['items'][1]['poll']['options'],
            'auch in der Gesamtauswertung verrät die Folge nichts');
        $this->assertNotSame(array_column(self::RANK, 'id'), array_column($summary['items'][1]['poll']['options'], 'id'),
            'hier nicht die Lösungsfolge');
    }

    public function testQuizSummaryAmEndeOhneNieGezeigteFragen(): void {
        // „Auflösung am Ende" nach /end: das Deck wird aufgedeckt — aber nur,
        // was auch dran war. Übersprungene Fragen bleiben draußen.
        $room = $this->room('quiz', active: 2, revealAtEnd: true);
        $this->polls->method('findByRoom')->willReturn([
            $this->poll(1, 'rank', 'locked', 900, self::RANK),
            $this->poll(2, 'choice', 'ended', 950, [['id' => 'AA', 'label' => 'A']]),
            $this->poll(3, 'choice', 'active', 0, [['id' => 'CC', 'label' => 'C']]),
        ]);

        $summary = $this->service->publicSummary($room, null);

        $this->assertSame([1, 2], array_map(static fn (array $i): int => $i['poll']['id'], $summary['items']));
        $this->assertTrue($summary['items'][0]['revealed']);
        $this->assertSame(['ZZ9A', 'AB12', 'M3K0'], array_column($summary['items'][0]['poll']['options'], 'id'),
            'aufgelöst → gespeicherte Folge (= Lösung)');
    }

    // ── Hilfen ─────────────────────────────────────────────────────────────

    /**
     * Erwartete Mischung, unabhängig vom Dienst nachgerechnet: aufsteigend
     * nach HMAC-SHA256 über „Frage-ID:Options-ID", Schlüssel „pulse-order:"
     * + Instanz-Geheimnis. Die gespeicherte Folge geht nicht ein.
     *
     * @param list<array{id:string,label:string}> $list
     * @return list<array{id:string,label:string}>
     */
    private static function hashFolge(int $pollId, array $list, string $secret): array {
        usort($list, static fn (array $a, array $b): int => strcmp(
            hash_hmac('sha256', $pollId . ':' . $a['id'], 'pulse-order:' . $secret),
            hash_hmac('sha256', $pollId . ':' . $b['id'], 'pulse-order:' . $secret),
        ));
        return $list;
    }

    /**
     * Ausgeliefert ist eine Umordnung der gespeicherten Liste: dieselben
     * Einträge, jede Beschriftung noch an ihrer ID, nichts doppelt.
     */
    private function assertIsPermutation(array $stored, array $served): void {
        $this->assertCount(count($stored), $served);
        $byId = array_column($served, null, 'id');
        $this->assertCount(count($stored), $byId, 'keine ID doppelt');
        foreach ($stored as $entry) {
            $this->assertSame($entry, $byId[$entry['id']] ?? null, 'Beschriftung wandert mit ihrer ID: ' . $entry['id']);
        }
    }

    /** @return list<string> */
    private function servedIds(Room $room, Poll $poll): array {
        return array_column($this->stateFor($room, $poll)['poll']['options'], 'id');
    }

    private function stateFor(Room $room, Poll $poll, ?string $token = null): array {
        $polls = $this->createMock(PollMapper::class);
        $polls->method('find')->willReturn($poll);
        (new ReflectionProperty(StateService::class, 'pollMapper'))->setValue($this->service, $polls);
        return $this->service->publicState($room, $token);
    }

    private function room(string $mode, int $active, bool $revealAtEnd = false): Room {
        $room = new Room();
        $room->setId(1);
        $room->setCode('ABCDEF');
        $room->setMode($mode);
        $room->setActivePollId($active);
        $room->setRevealAtEnd($revealAtEnd);
        return $room;
    }

    private function rankPoll(string $status): Poll {
        return $this->poll(7, 'rank', $status, 900, self::RANK);
    }

    private function poll(int $id, string $type, string $status, int $startedAt, array $options = [['id' => 'AA', 'label' => 'A']]): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setRoomId(1);
        $poll->setType($type);
        $poll->setQuestion('Frage ' . $id);
        $poll->setOptions(json_encode($options));
        $poll->setStatus($status);
        $poll->setStartedAt($startedAt);
        return $poll;
    }
}
