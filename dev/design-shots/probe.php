<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Prüfstand-Daten für die Design-Screenshots.
 *
 * Läuft IM Nextcloud-Container (braucht lib/base.php) und legt zwei Räume mit
 * je einer Frage pro Typ an, füllt sie über den echten Demo-Weg mit Stimmen und
 * schaltet auf Zuruf den Zustand um. `destroy` räumt alles wieder weg.
 *
 *   php probe.php create <uid>
 *   php probe.php state  <code> <pollId> open|locked
 *   php probe.php seed   <code> <n>
 *   php probe.php end    <code>
 *   php probe.php destroy <uid>
 *
 * Quiz im eigenen Tempo (Stufe 4):
 *   php probe.php pace <code> live|self
 *   php probe.php open <code> [closesIn] [timed 0|1] [feedback each|end]
 *   php probe.php close|release <code>
 *   php probe.php lock <code> 0|1
 *   php probe.php window <code> closesAt=<ts|now±s> closedAt=… releasedAt=…
 *   php probe.php race <code> <N> [seed]
 *   php probe.php remove <code> <name>
 *   php probe.php reset <code>
 *   php probe.php pace-create <uid>
 */

define('OC_CONSOLE', 1);
require '/var/www/html/lib/versioncheck.php';
require '/var/www/html/lib/base.php';

use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\DemoService;
use OCA\Pulse\Service\PollImageService;
use OCA\Pulse\Service\RoomService;

$rooms = \OCP\Server::get(RoomService::class);
$deck = \OCP\Server::get(DeckService::class);
$demo = \OCP\Server::get(DemoService::class);
$images = \OCP\Server::get(PollImageService::class);
$roomMapper = \OCP\Server::get(RoomMapper::class);
$pollMapper = \OCP\Server::get(PollMapper::class);

$cmd = $argv[1] ?? '';

/**
 * Ein Bild ohne Fremddatei: GD malt einen kleinen Balken-Chart. Der weiße Grund
 * ist Absicht — genau er leuchtet im Dunkelmodus, wenn die Trägerfläche fehlt
 * (§7.8). Die Form entscheidet den Prüffall: quer, hochkant oder Panorama.
 */
function makeImage(int $w = 900, int $h = 600): string {
	$img = imagecreatetruecolor($w, $h);
	imagefill($img, 0, 0, imagecolorallocate($img, 252, 252, 254));
	$base = (int)($h * 0.93);
	$cols = [[62, 132, 196], [236, 128, 64], [96, 176, 112], [148, 96, 196]];
	$step = (int)($w / 6);
	foreach ($cols as $i => [$r, $g, $b]) {
		$bh = (int)($h * (0.3 + 0.16 * (($i * 3) % 4)));
		$x = $step + $i * $step;
		imagefilledrectangle($img, $x, $base - $bh, $x + (int)($step * 0.6), $base, imagecolorallocate($img, $r, $g, $b));
	}
	imagefilledrectangle($img, (int)($w * 0.08), $base, (int)($w * 0.92), $base + 4, imagecolorallocate($img, 40, 45, 60));
	ob_start();
	imagepng($img);
	return (string)ob_get_clean();
}

/** Bild an eine Frage hängen (GD -> Upload-Weg der App). */
function attachImage(PollImageService $images, PollMapper $pollMapper, int $pollId, int $w, int $h): void {
	$tmp = tempnam(sys_get_temp_dir(), 'shot');
	file_put_contents($tmp, makeImage($w, $h));
	$images->store($pollMapper->find($pollId), [
		'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK,
	]);
	@unlink($tmp);
}

/** Frage anhängen und ID + Typ zurückgeben. */
function add(DeckService $deck, $room, array $data, string $label): array {
	$poll = $deck->addPoll($room, $data);
	return ['id' => $poll->getId(), 'type' => $data['type'], 'label' => $label];
}

if ($cmd === 'create') {
	$uid = $argv[2] ?? 'pulse-shots';

	// Umfrage-Raum: alle Typen ohne Lösung.
	$pollRoom = $rooms->createRoom($uid, 'poll', 'Design check · poll');
	$pollPolls = [
		add($deck, $pollRoom, [
			'type' => 'choice',
			'question' => 'Which release should we ship first?',
			'options' => ['Offline mode', 'Team spaces', 'Search overhaul', 'Dark theme'],
		], 'choice'),
		add($deck, $pollRoom, [
			'type' => 'words',
			'question' => 'One word: how did the workshop feel?',
			'maxWords' => 3,
		], 'words'),
		add($deck, $pollRoom, [
			'type' => 'scale',
			'question' => 'How ready are we for the launch?',
			'scaleMode' => 'single',
			'scaleMax' => 7,
			'minLabel' => 'Not at all',
			'maxLabel' => 'Fully ready',
		], 'scale-single'),
		add($deck, $pollRoom, [
			'type' => 'scale',
			'question' => 'Rate the prototype on four aspects',
			'scaleMode' => 'spectrum',
			'scaleMax' => 10,
			'aspects' => [
				['label' => 'Speed', 'poleLow' => 'Sluggish', 'poleHigh' => 'Instant'],
				['label' => 'Clarity', 'poleLow' => 'Confusing', 'poleHigh' => 'Obvious'],
				['label' => 'Beauty', 'poleLow' => 'Plain', 'poleHigh' => 'Polished'],
				['label' => 'Trust', 'poleLow' => 'Shaky', 'poleHigh' => 'Solid'],
			],
		], 'scale-spectrum'),
		add($deck, $pollRoom, [
			'type' => 'scale',
			'question' => 'Where do you stand?',
			'scaleMode' => 'compass',
			'range' => 5,
			'axisX' => ['title' => 'Pace', 'poleLow' => 'Careful', 'poleHigh' => 'Fast'],
			'axisY' => ['title' => 'Scope', 'poleLow' => 'Focused', 'poleHigh' => 'Broad'],
			'cornerLabels' => ['Steady', 'Sprinter', 'Curator', 'Explorer'],
		], 'scale-compass'),
		add($deck, $pollRoom, [
			'type' => 'rank',
			'question' => 'Sort these by importance for the next quarter',
			'options' => ['Reliability', 'New features', 'Documentation', 'Performance'],
		], 'rank'),
		add($deck, $pollRoom, [
			'type' => 'match',
			'question' => 'Which tool belongs to which job?',
			'pairs' => [
				['left' => 'Backup', 'right' => 'Borg'],
				['left' => 'Reverse proxy', 'right' => 'Traefik'],
				['left' => 'Database', 'right' => 'Postgres'],
				['left' => 'Cache', 'right' => 'Redis'],
			],
		], 'match'),
	];

	// Ein Bild an die erste Frage — die Bildfrage aus §7.1 (quer, 3:2).
	attachImage($images, $pollMapper, $pollPolls[0]['id'], 900, 600);

	// Quiz-Raum: alle Quiz-Typen mit Lösung.
	$quizRoom = $rooms->createRoom($uid, 'quiz', 'Design check · quiz');
	$quizPolls = [
		add($deck, $quizRoom, [
			'type' => 'choice',
			'question' => 'Which port does HTTPS use by default?',
			'options' => ['80', '443', '8080', '22'],
			'correctIndex' => 1,
			'timeLimit' => 30,
		], 'choice'),
		add($deck, $quizRoom, [
			'type' => 'truefalse',
			'question' => 'A room code is case sensitive.',
			'correctIndex' => 1,
			'timeLimit' => 20,
		], 'truefalse'),
		add($deck, $quizRoom, [
			'type' => 'multi',
			'question' => 'Which of these are databases?',
			'options' => ['Postgres', 'Redis', 'Traefik', 'MariaDB'],
			'correctIndexes' => [0, 3],
			'timeLimit' => 40,
		], 'multi'),
		add($deck, $quizRoom, [
			'type' => 'number',
			'question' => 'How many question types does Pulse offer?',
			'target' => 9,
			'tolerance' => 1,
			'timeLimit' => 30,
		], 'number'),
		add($deck, $quizRoom, [
			'type' => 'text',
			'question' => 'Which header blocks framing?',
			'answers' => ['X-Frame-Options'],
			'timeLimit' => 45,
		], 'text'),
		add($deck, $quizRoom, [
			'type' => 'rank',
			'question' => 'Sort from oldest to newest',
			'options' => ['HTTP/1.1', 'HTTP/2', 'HTTP/3'],
			'timeLimit' => 45,
		], 'rank'),
		add($deck, $quizRoom, [
			'type' => 'match',
			'question' => 'Match protocol to port',
			'pairs' => [
				['left' => 'SSH', 'right' => '22'],
				['left' => 'HTTPS', 'right' => '443'],
				['left' => 'DNS', 'right' => '53'],
				['left' => 'SMTP', 'right' => '25'],
			],
			'timeLimit' => 60,
		], 'match'),
	];

	// Grenzfall-Raum (§2.6, Prüffälle aus §11): Zeilendeckel bei zwei Optionen,
	// Schrumpf-Schleife bei acht, zweizeiliges Label — und ein Prüfwort mit
	// g, p, q, ü, ß, das abgeschnittene Unterlängen sofort sichtbar macht.
	$edgeRoom = $rooms->createRoom($uid, 'poll', 'Design check · edges');
	$edgePolls = [
		add($deck, $edgeRoom, [
			'type' => 'choice',
			'question' => 'Ship on Friday?',
			'options' => ['Yes', 'No'],
		], 'opts-2'),
		add($deck, $edgeRoom, [
			'type' => 'choice',
			'question' => 'Which topic should the next workshop cover?',
			'options' => ['Backups', 'Monitoring', 'Deployment', 'Security', 'Testing', 'Design', 'Accessibility', 'Performance'],
		], 'opts-8'),
		add($deck, $edgeRoom, [
			'type' => 'choice',
			// Bewusst deutsch: die Unterlängen g, p, q, ü, ß gibt es im
			// englischen Prüfsatz nicht, und genau sie werden abgeschnitten.
			'question' => 'Prüfung: gequälte Unterlängen bei Übergaben groß genug für die Rückgängig-Frage?',
			'options' => [
				'Rückgängig gemachte Änderungen später gequält prüfen und übergeben',
				'Übergabe gleich groß aufsetzen',
				'Später prüfen',
			],
		], 'long-label'),
	];

	// Fragetyp-Grenzfälle (§7.10/§11): Konstellationen, die im Bestand nicht
	// vorkommen und ohne die die Abnahme von Etappe 3 nicht zu belegen ist.
	$typeRoom = $rooms->createRoom($uid, 'poll', 'Design check · types');
	$typePolls = [
		add($deck, $typeRoom, [
			'type' => 'scale', 'question' => 'How ready are we for the launch?',
			'scaleMode' => 'single', 'scaleMax' => 7,
			'minLabel' => 'Not at all', 'maxLabel' => 'Fully ready',
		], 'scale-same'),
		add($deck, $typeRoom, [
			'type' => 'scale', 'question' => 'How ready are we for the launch?',
			'scaleMode' => 'single', 'scaleMax' => 7,
			'minLabel' => 'Not at all', 'maxLabel' => 'Fully ready',
		], 'scale-one'),
		// Zwei Aspekte lässt der Editor nicht zu (mindestens drei, §13 verbietet
		// das zu ändern) — geprüft wird deshalb der kleinstmögliche Radar.
		add($deck, $typeRoom, [
			'type' => 'scale', 'question' => 'Rate the prototype', 'scaleMode' => 'spectrum', 'scaleMax' => 7,
			'aspects' => [
				['label' => 'Speed', 'poleLow' => 'Sluggish', 'poleHigh' => 'Instant'],
				['label' => 'Clarity', 'poleLow' => 'Confusing', 'poleHigh' => 'Obvious'],
				['label' => 'Trust', 'poleLow' => 'Shaky', 'poleHigh' => 'Solid'],
			],
		], 'spectrum-3'),
		add($deck, $typeRoom, [
			'type' => 'scale', 'question' => 'Rate the prototype', 'scaleMode' => 'spectrum', 'scaleMax' => 7,
			'aspects' => [
				['label' => 'Speed', 'poleLow' => 'Sluggish', 'poleHigh' => 'Instant'],
				['label' => 'Clarity', 'poleLow' => 'Confusing', 'poleHigh' => 'Obvious'],
				['label' => 'Design', 'poleLow' => 'Plain', 'poleHigh' => 'Polished'],
				['label' => 'Trust', 'poleLow' => 'Shaky', 'poleHigh' => 'Solid'],
				['label' => 'Understandability', 'poleLow' => 'Opaque', 'poleHigh' => 'Plain'],
				['label' => 'Usefulness', 'poleLow' => 'Nice to have', 'poleHigh' => 'Essential'],
				['label' => 'Effort', 'poleLow' => 'Heavy', 'poleHigh' => 'Light'],
				['label' => 'Maturity', 'poleLow' => 'Early', 'poleHigh' => 'Proven'],
			],
		], 'spectrum-8'),
		add($deck, $typeRoom, [
			'type' => 'scale', 'question' => 'Where do you stand?', 'scaleMode' => 'compass', 'range' => 5,
			'axisX' => ['title' => 'Pace', 'poleLow' => 'Careful', 'poleHigh' => 'Fast'],
			'axisY' => ['title' => 'Scope', 'poleLow' => 'Focused', 'poleHigh' => 'Broad'],
			'cornerLabels' => ['Steady', 'Sprinter', 'Curator', 'Explorer'],
		], 'compass-threshold'),
		add($deck, $typeRoom, [
			'type' => 'rank', 'question' => 'Sort these by importance for the next quarter',
			'options' => ['Reliability', 'New features', 'Performance', 'Documentation'],
		], 'rank-polar'),
		add($deck, $typeRoom, [
			'type' => 'choice', 'question' => 'Which detail stands out?',
			'options' => ['The trend', 'The outlier', 'The baseline'],
		], 'image-portrait'),
		add($deck, $typeRoom, [
			'type' => 'choice', 'question' => 'Which detail stands out?',
			'options' => ['The trend', 'The outlier', 'The baseline'],
		], 'image-panorama'),
		add($deck, $typeRoom, [
			'type' => 'choice', 'question' => 'Should we ship on Friday?',
			'options' => ['Yes', 'No', 'Only the backend'],
		], 'no-votes'),
		// Zuordnung mit acht Paaren: der Bestand hat nur vier, und erst ab etwa
		// sechs Zeilen entscheidet sich, ob Beamer und Moderationsansicht das
		// tragen. Beschriftungen bewusst unterschiedlich lang.
		add($deck, $typeRoom, [
			'type' => 'match',
			'question' => 'Which tool belongs to which job?',
			'pairs' => [
				['left' => 'Backup', 'right' => 'Borg'],
				['left' => 'Reverse proxy', 'right' => 'Traefik'],
				['left' => 'Database', 'right' => 'Postgres'],
				['left' => 'Cache', 'right' => 'Redis'],
				['left' => 'Container orchestration', 'right' => 'Kubernetes'],
				['left' => 'Metrics and dashboards', 'right' => 'Grafana'],
				['left' => 'Log aggregation', 'right' => 'Loki'],
				['left' => 'Configuration management', 'right' => 'Ansible'],
			],
		], 'match-8'),
	];
	attachImage($images, $pollMapper, $typePolls[6]['id'], 620, 900);
	attachImage($images, $pollMapper, $typePolls[7]['id'], 1600, 420);

	// Acht Optionen MIT Bild: der Prüffall aus §11 für die Falz-Regel.
	attachImage($images, $pollMapper, $edgePolls[1]['id'], 900, 600);

	// Dieselbe Zuordnung als Quiz: hier kommt die Lösungsfarbe dazu, und die
	// Moderationsansicht zeigt das private Panel neben der Leinwand.
	$matchRoom = $rooms->createRoom($uid, 'quiz', 'Design check · match');
	$matchPolls = [
		add($deck, $matchRoom, [
			'type' => 'match',
			'question' => 'Match the service to its default port',
			'pairs' => [
				['left' => 'SSH', 'right' => '22'],
				['left' => 'HTTPS', 'right' => '443'],
				['left' => 'DNS', 'right' => '53'],
				['left' => 'SMTP submission', 'right' => '587'],
				['left' => 'PostgreSQL', 'right' => '5432'],
				['left' => 'Redis', 'right' => '6379'],
				['left' => 'NTP', 'right' => '123'],
				['left' => 'IMAP over TLS', 'right' => '993'],
			],
			'timeLimit' => 60,
		], 'match-8'),
		add($deck, $matchRoom, [
			'type' => 'match',
			'question' => 'Match protocol to port',
			'pairs' => [
				['left' => 'SSH', 'right' => '22'],
				['left' => 'HTTPS', 'right' => '443'],
				['left' => 'DNS', 'right' => '53'],
				['left' => 'SMTP', 'right' => '25'],
			],
			'timeLimit' => 60,
		], 'match-4'),
	];

	// Raum ohne eine einzige Frage (§9.8): die Präsentationsansicht muss ihn
	// tragen — „Noch keine Frage" plus „Frage anlegen" als Hauptaktion.
	$emptyRoom = $rooms->createRoom($uid, 'quiz', 'Design check · empty');

	// Endstand-Grenzfälle: neun Teilnehmende (Plätze 4–8 plus „+N weitere") und
	// zwei (rechte Spalte entfällt, Podium zentriert).
	$finals = [];
	foreach (['final-9' => 9, 'final-2' => 2] as $label => $count) {
		$room = $rooms->createRoom($uid, 'quiz', 'Design check · ' . $label);
		$poll = add($deck, $room, [
			'type' => 'choice',
			'question' => 'Which port does HTTPS use by default?',
			'options' => ['80', '443', '8080', '22'],
			'correctIndex' => 1,
			'timeLimit' => 30,
		], 'choice');
		$finals[$label] = ['code' => $room->getCode(), 'players' => $count, 'polls' => [$poll]];
	}

	echo json_encode([
		'poll' => ['code' => $pollRoom->getCode(), 'polls' => $pollPolls],
		'quiz' => ['code' => $quizRoom->getCode(), 'polls' => $quizPolls],
		'edge' => ['code' => $edgeRoom->getCode(), 'polls' => $edgePolls],
		'types' => ['code' => $typeRoom->getCode(), 'polls' => $typePolls],
		'match' => ['code' => $matchRoom->getCode(), 'polls' => $matchPolls],
		'empty' => ['code' => $emptyRoom->getCode(), 'polls' => []],
		'finals' => $finals,
	], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
	exit(0);
}

if ($cmd === 'state') {
	$room = $roomMapper->findByCode((string)$argv[2]);
	$pollId = (int)$argv[3];
	$want = $argv[4] ?? 'open';
	$deck->setCurrent($room, $pollId);
	if ($want === 'locked') {
		$deck->lockPoll($room, $pollId);
	} else {
		$deck->unlockPoll($room, $pollId);
	}
	echo "ok\n";
	exit(0);
}

/*
 * Präsenz vortäuschen: N Heartbeats mit erfundenen Tokens. Die Eingangs-Anzeige
 * setzt die eingegangenen Antworten dazu ins Verhältnis („12 von 24"), und ohne
 * echte Handys stünde dort sonst immer „0". Das Fenster ist kurz (15 s) — der
 * Screenshot muss also direkt danach fallen.
 */
if ($cmd === 'present') {
    $room = $roomMapper->findByCode((string)$argv[2]);
    $count = (int)($argv[3] ?? 24);
    for ($i = 0; $i < $count; $i++) {
        $rooms->heartbeat($room, 'shots-' . $i);
    }
    echo "ok\n";
    exit(0);
}

/*
 * Countdown auf einen bestimmten Reststand stellen: der Startzeitpunkt wandert
 * so weit zurück, dass genau <seconds> übrig bleiben. Anders ist die
 * Dringlichkeitsstufe (unter 10 s) nicht reproduzierbar zu fotografieren.
 */
if ($cmd === 'countdown') {
    $poll = $pollMapper->find((int)$argv[3]);
    $left = (int)($argv[4] ?? 9);
    $poll->setStartedAt(time() - max(0, $poll->getTimeLimit() - $left));
    $pollMapper->update($poll);
    echo "ok\n";
    exit(0);
}

/*
 * Deterministische Stimmen für die Konstellationen aus §7.10. Zufall taugt
 * dafür nicht: „Ø ≈ Median" oder „alle Stimmen auf einem Wert" sind genau die
 * Fälle, die eine Zufallsverteilung nie trifft, und die Heatmap-Schwelle muss
 * bei 44 und bei 46 Antworten fotografierbar sein, nicht „ungefähr".
 *
 *   php probe.php fixture <code> <pollId> scale-same|scale-one|compass|rank-polar|words [n]
 */
if ($cmd === 'fixture') {
    $room = $roomMapper->findByCode((string)$argv[2]);
    $poll = $pollMapper->find((int)$argv[3]);
    $kind = (string)($argv[4] ?? '');
    $arg = (int)($argv[5] ?? 0);
    $voteMapper = \OCP\Server::get(\OCA\Pulse\Db\VoteMapper::class);
    $now = time();
    $i = 0;
    // Fortlaufend ab dem Bestand: ein zweiter Aufruf ergänzt Stimmen, statt an
    // der Token-Eindeutigkeit zu scheitern (44 Antworten, dann 46).
    $base = $voteMapper->countByPoll($poll->getId());
    $put = function (mixed $value) use ($voteMapper, $poll, $now, $base, &$i): void {
        $vote = new \OCA\Pulse\Db\Vote();
        $vote->setPollId($poll->getId());
        // "demo:"-Präfix -> dieselbe Aufräumung wie synthetische Stimmen.
        $vote->setVoterToken('demo:fix-' . $poll->getId() . '-' . ($base + $i));
        $vote->setPayload(json_encode(['value' => $value]));
        $vote->setCreatedAt($now + $i);
        $voteMapper->insert($vote);
        $i++;
    };

    if ($kind === 'scale-same') {
        // Symmetrisch um 4: Ø 4,0 und Median 4 fallen zusammen — die beiden
        // Kennwerte stehen übereinander und dürfen sich trotzdem nicht decken.
        foreach ([1 => 1, 2 => 2, 3 => 5, 4 => 9, 5 => 5, 6 => 2, 7 => 0] as $value => $count) {
            for ($k = 0; $k < $count; $k++) {
                $put($value);
            }
        }
    } elseif ($kind === 'scale-one') {
        for ($k = 0; $k < 24; $k++) {
            $put(4);
        }
    } elseif ($kind === 'compass') {
        // Eigener Zufallsgenerator (LCG) statt rand(): derselbe Aufruf ergibt
        // dieselbe Punktwolke, sonst wäre der Vergleich 44 gegen 46 wertlos.
        $count = $arg > 0 ? $arg : 44;
        $seed = 4711;
        $next = function () use (&$seed): float {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;
            return $seed / 2147483648;
        };
        for ($k = 0; $k < $count; $k++) {
            $gx = ($next() + $next() + $next()) / 3;
            $gy = ($next() + $next() + $next()) / 3;
            $put(['x' => (int)round(($gx - 0.42) * 9), 'y' => (int)round(($gy - 0.46) * 9)]);
        }
    } elseif ($kind === 'rank-polar') {
        // Ein Element mit vielen ersten UND vielen letzten Plätzen: genau der
        // Fall, den der Durchschnitt allein unsichtbar macht (§7.5).
        $ids = array_column($poll->getOptionsArray(), 'id');
        [$a, $b, $c, $d] = $ids;
        $orders = [
            [[$a, $b, $c, $d], 6],
            [[$b, $a, $d, $c], 5],
            [[$c, $b, $a, $d], 4],
            [[$d, $c, $b, $a], 7],
            [[$d, $a, $c, $b], 3],
        ];
        foreach ($orders as [$order, $count]) {
            for ($k = 0; $k < $count; $k++) {
                $put($order);
            }
        }
    } elseif ($kind === 'words') {
        // Feste Verteilung statt Zufall: die Wortwolke der Store-Seite soll bei
        // jedem Lauf gleich aussehen. Ein Zufallslauf trifft weder die Staffelung
        // der Schriftgrößen noch eine ansehnliche Zahl verschiedener Wörter.
        // Dieselben Begriffe wie DemoService::demoWords().
        $counts = [
            'Faster' => 6, 'Cleaner' => 5, 'Cluttered' => 4, 'Familiar' => 4,
            'Snappy' => 3, 'Confusing' => 3, 'Polished' => 3, 'Dense' => 3,
            'Intuitive' => 2, 'Busy' => 2, 'Lighter' => 2, 'Crowded' => 2,
            'Clear' => 2, 'Bold' => 1, 'Flat' => 1, 'Quiet' => 1,
        ];
        foreach ($counts as $word => $count) {
            for ($k = 0; $k < $count; $k++) {
                $put([$word]);
            }
        }
    } else {
        fwrite(STDERR, "Unbekannte Fixture: $kind\n");
        exit(1);
    }
    echo "ok: $i\n";
    exit(0);
}

if ($cmd === 'seed') {
	$room = $roomMapper->findByCode((string)$argv[2]);
	$demo->seedDemoVotes($room, (int)($argv[3] ?? 24));
	echo "ok\n";
	exit(0);
}

if ($cmd === 'end') {
	$rooms->endQuiz($roomMapper->findByCode((string)$argv[2]));
	echo "ok\n";
	exit(0);
}

/**
 * Die Antwort einer Person auf eine Quiz-Frage: entweder die Lösung oder eine
 * beliebige andere Option. Beide Typen im Store-Quiz legen ihre Optionen mit
 * IDs ab und tragen die Lösung als ID in correctOption.
 */
function storeAnswer($poll, bool $correct, int $seed): string {
	$ids = array_map('strval', array_column($poll->getOptionsArray(), 'id'));
	$right = (string)$poll->getCorrectOption();
	if ($correct) { return $right; }
	$wrong = array_values(array_filter($ids, static fn ($id) => $id !== $right));
	// Die falschen Stimmen verteilen sich, sonst zeigt das Panel zwei Balken
	// und drei Nullen — auf einem Werbebild sieht das nach Attrappe aus.
	return $wrong === [] ? $right : $wrong[$seed % count($wrong)];
}

/*
 * Vorlage für die Screenshots der Store-Seite. Anders als `create` geht es
 * hier nicht um Prüffälle, sondern um Bilder, die jemand ohne Vorwissen liest:
 * wenige Räume, sprechende Fragen, runde Zahlen.
 *
 * Die Rangliste wird bewusst NICHT über den Demo-Weg gefüllt. Der vergibt je
 * Stimme ein neues Token und registriert damit je Frage neue Spielende — auf
 * dem Podest steht dann zweimal derselbe Name mit „shared" daneben, und bei
 * mehr Stimmen als Namen hängt eine „2" hinten dran. Hier antworten stattdessen
 * zwölf Personen mit festem Token auf jede Frage.
 */
if ($cmd === 'store') {
	$uid = $argv[2] ?? 'pulse-shots';

	// Umfrage-Raum: die drei Typen, die man auf einen Blick versteht.
	$pollRoom = $rooms->createRoom($uid, 'poll', 'Team workshop');
	$pollPolls = [
		add($deck, $pollRoom, [
			'type' => 'words',
			'question' => 'One word: how does the new dashboard feel?',
			// Wirklich ein Wort: die Frage sagt es, also soll die App es auch
			// durchsetzen. Nebenbei ist damit jede Stimme genau eine Nennung,
			// und die Zahlen unter der Wolke gehen auf.
			'maxWords' => 1,
		], 'words'),
		add($deck, $pollRoom, [
			'type' => 'choice',
			'question' => 'Which release should we ship first?',
			'options' => ['Offline mode', 'Team spaces', 'Search overhaul', 'Dark theme'],
		], 'choice'),
		add($deck, $pollRoom, [
			'type' => 'scale',
			'question' => 'How ready are we for the launch?',
			'scaleMode' => 'single',
			'scaleMax' => 7,
			'minLabel' => 'Not at all',
			'maxLabel' => 'Fully ready',
		], 'scale'),
	];

	// Quiz-Raum: nur Typen mit eindeutiger Lösung, damit die Rangliste stimmt.
	$quizRoom = $rooms->createRoom($uid, 'quiz', 'Quiz night');
	$quizPolls = [
		add($deck, $quizRoom, [
			'type' => 'choice',
			'question' => 'Which port does HTTPS use by default?',
			'options' => ['80', '443', '8080', '22'],
			'correctIndex' => 1,
			'timeLimit' => 30,
		], 'choice'),
		add($deck, $quizRoom, [
			'type' => 'truefalse',
			'question' => 'Voting in Pulse needs a Nextcloud account.',
			'correctIndex' => 1,
			'timeLimit' => 20,
		], 'truefalse'),
		add($deck, $quizRoom, [
			'type' => 'choice',
			'question' => 'Which of these is not a database?',
			'options' => ['PostgreSQL', 'MariaDB', 'Traefik', 'SQLite'],
			'correctIndex' => 2,
			'timeLimit' => 30,
		], 'choice-db'),
		add($deck, $quizRoom, [
			'type' => 'choice',
			'question' => 'How does the audience join a room?',
			'options' => ['By invitation mail', 'With a six-character code', 'Through an app store', 'With a group share'],
			'correctIndex' => 1,
			'timeLimit' => 30,
		], 'choice-join'),
		add($deck, $quizRoom, [
			'type' => 'truefalse',
			'question' => 'A room code is case sensitive.',
			'correctIndex' => 1,
			'timeLimit' => 20,
		], 'truefalse-code'),
	];

	/*
	 * Zwölf Spielende, jede Person mit festem Token über alle fünf Fragen.
	 *
	 * Wer welche Frage trifft, steht als Muster da (1 = richtig) statt als
	 * Formel: nur so hat jede einzelne Frage eine Verteilung, die man sich
	 * ansehen mag — „alle richtig" wäre auf dem Bild ein einziger Balken auf
	 * 100 %. Die Zeilen sind nach Treffern sortiert und die Antwortzeit steigt
	 * mit dem Index, also sind Punktzahl und Reihenfolge eindeutig und das
	 * Podest zeigt drei verschiedene Namen ohne geteilte Ränge.
	 */
	$voteMapper = \OCP\Server::get(\OCA\Pulse\Db\VoteMapper::class);
	$playerMapper = \OCP\Server::get(\OCA\Pulse\Db\PlayerMapper::class);
	$voteService = \OCP\Server::get(\OCA\Pulse\Service\VoteService::class);
	$names = ['Mira', 'Jonas', 'Aiko', 'Tomás', 'Lena', 'Omar', 'Nils', 'Fatou', 'Ida', 'Ben', 'Rosa', 'Yusuf'];
	$sheet = ['11111', '11110', '11101', '10111', '11100', '10110',
		'01101', '10100', '01010', '00101', '10000', '00010'];
	$now = time();
	foreach ($names as $i => $name) {
		$token = 'demo:store-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
		$playerMapper->register($quizRoom->getId(), $token, $name, $now);
		foreach ($quizPolls as $q => $meta) {
			$poll = $pollMapper->find($meta['id']);
			$raw = storeAnswer($poll, $sheet[$i][$q] === '1', $i + $q);
			$normalized = $voteService->normalizeValue($poll, $raw);
			$elapsed = 2 + $i;
			$vote = new \OCA\Pulse\Db\Vote();
			$vote->setPollId($poll->getId());
			$vote->setVoterToken($token);
			$vote->setPayload((string)json_encode($voteService->quizPayload($poll, $normalized, $elapsed)));
			$vote->setCreatedAt($now + $i);
			$voteMapper->insert($vote);
		}
	}

	echo json_encode([
		'poll' => ['code' => $pollRoom->getCode(), 'polls' => $pollPolls],
		'quiz' => ['code' => $quizRoom->getCode(), 'polls' => $quizPolls, 'players' => count($names)],
	], JSON_PRETTY_PRINT) . "\n";
	exit(0);
}
/*
 * ── Quiz im eigenen Tempo (Stufe 4, §6.1) ─────────────────────────────────
 *
 * Alles über die echten Services (PaceService, VoteService, Mapper), damit die
 * Aufnahmen nur Zustände zeigen, die die Engine wirklich erzeugt. Roh sind nur
 * `window` (Zeitstempel direkt, abgelaufene Frist ohne Warten) und das
 * Zurückdatieren von Start- und Stimmzeiten in `race`.
 */
$pace = \OCP\Server::get(\OCA\Pulse\Service\PaceService::class);

/** Raum per Code oder Abbruch mit Meldung (kein Stacktrace im Prüfstand-Log). */
function paceRoom(RoomMapper $roomMapper, string $code) {
	try {
		return $roomMapper->findByCode($code);
	} catch (\Throwable $e) {
		fwrite(STDERR, "Raum nicht gefunden: $code\n");
		exit(1);
	}
}

/** Fensterzustand nach einer Aktion, eine Zeile JSON. */
function paceWindow($room, PollMapper $pollMapper): string {
	return json_encode(\OCA\Pulse\Service\PaceService::windowView($room, time(), count($pollMapper->findByRoom($room->getId()))));
}

/** Service-Aufruf; Fachfehler (400/409 im Controller) als Meldung, Exit 1. */
function paceRun(callable $fn) {
	try {
		return $fn();
	} catch (\InvalidArgumentException | \OCA\Pulse\Service\ConflictException $e) {
		fwrite(STDERR, 'abgelehnt: ' . $e->getMessage() . "\n");
		exit(1);
	}
}

if ($cmd === 'pace') {
	$room = paceRoom($roomMapper, (string)($argv[2] ?? ''));
	$room = paceRun(fn () => $pace->setPace($room, (string)($argv[3] ?? '')));
	echo 'ok ', $room->getPace(), "\n";
	exit(0);
}

/*
 *   php probe.php open <code> [closesIn] [timed 0|1] [feedback each|end]
 * closesIn = Sekunden ab jetzt, 0 = ohne Frist (sonst ≥ 60, sagt der Server).
 * Fehlt timed/feedback, gilt die Vorgabe des Servers (ohne Frist Rennen, mit
 * Frist Hausaufgabe).
 */
if ($cmd === 'open') {
	$room = paceRoom($roomMapper, (string)($argv[2] ?? ''));
	$in = (int)($argv[3] ?? 0);
	$timed = isset($argv[4]) ? $argv[4] === '1' : null;
	$feedback = isset($argv[5]) ? (string)$argv[5] : null;
	$room = paceRun(fn () => $pace->openWindow($room, $in > 0 ? time() + $in : 0, $timed, $feedback));
	echo 'ok ', paceWindow($room, $pollMapper), "\n";
	exit(0);
}

if ($cmd === 'close' || $cmd === 'release') {
	$room = paceRoom($roomMapper, (string)($argv[2] ?? ''));
	$room = paceRun(fn () => $cmd === 'close' ? $pace->closeWindow($room) : $pace->releaseWindow($room));
	echo 'ok ', paceWindow($room, $pollMapper), "\n";
	exit(0);
}

if ($cmd === 'lock') {
	$room = paceRoom($roomMapper, (string)($argv[2] ?? ''));
	$room = paceRun(fn () => $pace->setJoinsLocked($room, ($argv[3] ?? '1') === '1'));
	echo 'ok joinsLocked=', $room->getJoinsLocked() ? '1' : '0', "\n";
	exit(0);
}

/*
 *   php probe.php window <code> closesAt=<ts|now±s> closedAt=… releasedAt=… openedAt=…
 * Schreibt die Zeitstempel roh (nur für Prüffälle): z. B. closesAt=now-1 lässt
 * eine Frist sofort ablaufen, ohne dass jemand wartet.
 */
if ($cmd === 'window') {
	$room = paceRoom($roomMapper, (string)($argv[2] ?? ''));
	$now = time();
	foreach (array_slice($argv, 3) as $arg) {
		if (!preg_match('/^(closesAt|closedAt|releasedAt|openedAt)=(now([+-]\d+)?|\d+)$/', $arg, $m)) {
			fwrite(STDERR, "Unbekannt: $arg (erwartet closesAt|closedAt|releasedAt|openedAt=<ts|now±s>)\n");
			exit(1);
		}
		$value = str_starts_with($m[2], 'now') ? $now + (int)($m[3] ?? 0) : (int)$m[2];
		$room->{'set' . ucfirst($m[1])}($value);
	}
	$roomMapper->update($room);
	echo 'ok ', paceWindow($roomMapper->findByCode($room->getCode()), $pollMapper), "\n";
	exit(0);
}

if ($cmd === 'remove') {
	$room = paceRoom($roomMapper, (string)($argv[2] ?? ''));
	$want = \OCA\Pulse\Service\TallyService::nameKey((string)($argv[3] ?? ''));
	$playerMapper = \OCP\Server::get(\OCA\Pulse\Db\PlayerMapper::class);
	$found = null;
	foreach ($playerMapper->findByRoom($room->getId()) as $p) {
		if (\OCA\Pulse\Service\TallyService::nameKey($p->getNickname()) === $want) {
			$found = $p;
		}
	}
	if ($found === null) {
		fwrite(STDERR, 'Keine Person „' . ($argv[3] ?? '') . "“ in diesem Raum\n");
		exit(1);
	}
	paceRun(fn () => $pace->removePlayer($room, (int)$found->getId()));
	echo 'ok entfernt: ', $found->getNickname(), "\n";
	exit(0);
}

/*
 *   php probe.php leave <code> <name>
 * Schließt die offene Frage der Person, ohne die nächste zu starten — wie ein
 * /next, das zwischen Schließen und Starten abbrach (nur für Prüffälle). Das
 * Handy zeigt dann „Your quiz continues.", und /next {after} heilt es.
 */
if ($cmd === 'leave') {
	$room = paceRoom($roomMapper, (string)($argv[2] ?? ''));
	$want = \OCA\Pulse\Service\TallyService::nameKey((string)($argv[3] ?? ''));
	$found = null;
	foreach (\OCP\Server::get(\OCA\Pulse\Db\PlayerMapper::class)->findByRoom($room->getId()) as $p) {
		if (\OCA\Pulse\Service\TallyService::nameKey($p->getNickname()) === $want) {
			$found = $p;
		}
	}
	$open = $found === null ? null : $pace->openRow($room, $found->getVoterToken());
	if ($open === null) {
		fwrite(STDERR, 'Keine offene Frage bei „' . ($argv[3] ?? '') . "“ in diesem Raum\n");
		exit(1);
	}
	\OCP\Server::get(\OCA\Pulse\Db\ProgressMapper::class)->closeIfOpen((int)$open->getId(), time());
	echo 'ok verlassen: ', $found->getNickname(), ' (Frage ', $open->getSeq() + 1, ")\n";
	exit(0);
}

// Zurücksetzen wie „Reset quiz" (unter der Raumsperre, aber ohne die
// „erst schließen"-Sperre des Controllers — der Prüfstand darf das auch offen).
if ($cmd === 'reset') {
	$room = paceRoom($roomMapper, (string)($argv[2] ?? ''));
	$pace->locked($room, function ($r) use ($rooms): void {
		$rooms->resetRoom($r);
	});
	echo 'ok ', paceWindow($roomMapper->findByCode($room->getCode()), $pollMapper), "\n";
	exit(0);
}

/*
 *   php probe.php online <code> finished|started|all
 * Präsenz der Personen selbst (gilt 15 s): sie stehen dann in /progress als
 * „online". `finished` = nur, wer fertig ist — die Statuszeile „alle durch".
 */
if ($cmd === 'online') {
	$room = paceRoom($roomMapper, (string)($argv[2] ?? ''));
	$which = (string)($argv[3] ?? 'all');
	$state = \OCP\Server::get(\OCA\Pulse\Service\PaceStateService::class)->progress($room, false);
	$want = [];
	foreach ($state['players'] as $p) {
		if ($which === 'all' || ($which === 'finished' && $p['finished']) || ($which === 'started' && $p['started'])) {
			$want[(int)$p['id']] = true;
		}
	}
	$playerMapper = \OCP\Server::get(\OCA\Pulse\Db\PlayerMapper::class);
	$n = 0;
	foreach ($playerMapper->findByRoom($room->getId()) as $p) {
		if (isset($want[(int)$p->getId()])) {
			$rooms->heartbeat($room, $p->getVoterToken());
			$n++;
		}
	}
	echo "ok online: $n\n";
	exit(0);
}

/** Deterministischer Zufall (derselbe LCG wie `fixture compass`). */
function paceRandom(int $seed): callable {
	$state = $seed & 0x7fffffff;
	return function () use (&$state): float {
		$state = ($state * 1103515245 + 12345) % 2147483648;
		return $state / 2147483648;
	};
}

/**
 * Rohwert einer Antwort, wie ihn ein Handy an /vote schickt: die Lösung oder
 * eine gezielt falsche. Freitext falsch = eine von drei Gruppen, die weder
 * angenommen noch abgelehnt sind -> „wird geprüft" (pending).
 */
function paceAnswer($poll, bool $correct, callable $rnd): mixed {
	$key = $poll->getAnswerKeyArray();
	$ids = array_map('strval', array_column($poll->getOptionsArray(), 'id'));
	switch ($poll->getType()) {
		case 'choice':
		case 'truefalse':
			$right = (string)$poll->getCorrectOption();
			if ($correct) {
				return $right;
			}
			$wrong = array_values(array_diff($ids, [$right]));
			return $wrong[(int)floor($rnd() * count($wrong))];
		case 'multi':
			$want = array_map('strval', $key['correct'] ?? []);
			if ($correct) {
				return $want;
			}
			$wrong = array_values(array_diff($ids, $want));
			// eine richtige weglassen und eine falsche dazu — nie leer
			return array_values(array_merge(array_slice($want, 1), $wrong === [] ? [] : [$wrong[0]])) ?: [$ids[0]];
		case 'number':
			$target = (float)($key['target'] ?? 0);
			$tol = (float)($key['tolerance'] ?? 0);
			return $correct ? $target : $target + $tol + 1 + (int)floor($rnd() * 5);
		case 'text':
			if ($correct) {
				return (string)(($key['accepted'] ?? ['?'])[0]);
			}
			$groups = ['Content-Security-Policy', 'frame-ancestors', 'X-Frame'];
			return $groups[(int)floor($rnd() * 3)];
		case 'match':
			$map = $key['map'] ?? [];
			if ($correct || count($map) < 2) {
				return $map;
			}
			$items = array_keys($map);
			[$a, $b] = [$items[0], $items[1]];
			[$map[$a], $map[$b]] = [$map[$b], $map[$a]];
			return $map;
		case 'rank':
			$order = $key['order'] ?? $ids;
			return $correct ? $order : array_reverse($order);
	}
	throw new \RuntimeException('Fragetyp ohne Probe-Antwort: ' . $poll->getType());
}

/*
 *   php probe.php race <code> <N> [seed]
 *
 * N Personen treten bei (VoteService::quizJoin — der Service kennt das
 * Beitrittslimit von 120 je IP nicht, ein HTTP-Seed liefe hinein). Ist das
 * Fenster offen, laufen sie los: ~15 % bleiben ungestartet, der Rest geht per
 * PaceService::next bis zu seiner Zielfrage, ~30 % bis zum Ende (die Hälfte
 * davon tippt noch „Fertig"). Vor jeder Antwort wird die offene Zeile um eine
 * Zeit innerhalb des Limits zurückdatiert, dann antwortet VoteService (Punkte
 * nach der echten Formel). Zwei Personen überspringen Frage 1 (mit Timer erst
 * nach Zeitablauf — sonst wäre /next wegen der Vorschau-Sperre ein No-op).
 * Zuletzt gehen alle Stimmen dieses Aufrufs 60 s zurück: sie sind damit
 * endgültig, und die Zahlen stehen sofort fest.
 *
 * Ausgabe: die erwarteten Zahlen (je Person und je Frage) als JSON — zum
 * Gegenlesen gegen /progress und /state?spectate=1.
 */
function paceRace(string $code, int $count, int $seed): array {
	$roomMapper = \OCP\Server::get(RoomMapper::class);
	$pollMapper = \OCP\Server::get(PollMapper::class);
	$pace = \OCP\Server::get(\OCA\Pulse\Service\PaceService::class);
	$room = paceRoom($roomMapper, $code);
	$count = max(0, $count);
	$rnd = paceRandom($seed);
	$votes = \OCP\Server::get(\OCA\Pulse\Service\VoteService::class);
	$codes = \OCP\Server::get(\OCA\Pulse\Service\CodeGenerator::class);
	$playerMapper = \OCP\Server::get(\OCA\Pulse\Db\PlayerMapper::class);
	$progressMapper = \OCP\Server::get(\OCA\Pulse\Db\ProgressMapper::class);
	$voteMapper = \OCP\Server::get(\OCA\Pulse\Db\VoteMapper::class);
	$db = \OCP\Server::get(\OCP\IDBConnection::class);
	if (!\OCA\Pulse\Service\PaceService::isSelf($room)) {
		fwrite(STDERR, "Raum läuft nicht im eigenen Tempo\n");
		exit(1);
	}

	// Feste Namensliste; freie Namen der Reihe nach (auch bei einem zweiten Aufruf).
	$names = ['Anna', 'Ben', 'Cem', 'Dora', 'Emil', 'Fatou', 'Gino', 'Hanna', 'Ilyas', 'Jana',
		'Karl', 'Lea', 'Mehmet', 'Nora', 'Oskar', 'Paula', 'Quinn', 'Rosa', 'Sami', 'Tilda',
		'Uwe', 'Vera', 'Wim', 'Yara', 'Zoe', 'Aiko', 'Bruno', 'Clara', 'Deniz', 'Elif'];
	$taken = [];
	foreach ($playerMapper->findByRoom($room->getId()) as $p) {
		$taken[\OCA\Pulse\Service\TallyService::nameKey($p->getNickname())] = true;
	}
	$people = [];
	for ($i = 0; count($people) < $count; $i++) {
		$name = $names[$i % count($names)] . ($i >= count($names) ? ' ' . (intdiv($i, count($names)) + 1) : '');
		$nk = \OCA\Pulse\Service\TallyService::nameKey($name);
		if (isset($taken[$nk])) {
			continue;
		}
		$taken[$nk] = true;
		$token = $codes->voterToken();
		paceRun(fn () => $votes->quizJoin($room, $token, $name));
		$people[] = ['nickname' => $name, 'token' => $token];
	}

	$room = $roomMapper->findByCode($room->getCode());
	$now = time();
	$state = \OCA\Pulse\Service\PaceService::deriveState($room, $now);
	$order = \OCA\Pulse\Service\PaceService::order($room);
	$n = count($order);
	$polls = [];
	foreach ($order as $pid) {
		$polls[$pid] = $pollMapper->find($pid);
	}
	$timed = (bool)$room->getTimed();
	$openRow = function (string $token) use ($progressMapper, $room) {
		return \OCA\Pulse\Service\PaceService::openOf($progressMapper->findByRoomAndToken($room->getId(), $token));
	};
	// Zeile zurückdatieren (roh): so läuft die Uhr der Person schon eine Weile.
	$backdate = function ($row, int $seconds) use ($progressMapper): void {
		$row->setStartedAt(time() - $seconds);
		$progressMapper->update($row);
	};

	$expect = [];
	$perQ = array_fill(0, $n, ['reached' => 0, 'answered' => 0, 'correct' => 0, 'pending' => 0]);
	$skippers = 0;
	if ($state === \OCA\Pulse\Service\PaceService::STATE_OPEN && $n > 0) {
		foreach ($people as $idx => $who) {
			$token = $who['token'];
			$e = ['nickname' => $who['nickname'], 'k' => 0, 'started' => false, 'finished' => false,
				'answered' => 0, 'skipped' => 0, 'correct' => 0, 'score' => 0, 'pending' => 0, 'open' => false];
			if ($rnd() < 0.15) {
				$expect[] = $e;
				continue;
			}
			$toEnd = $rnd() < 0.30;
			$target = ($toEnd || $n === 1) ? $n : 1 + (int)floor($rnd() * ($n - 1));
			$done = $toEnd && $rnd() < 0.5; // „Fertig" getippt
			$skipFirst = $skippers < 2 && $target >= 2 && $idx % 3 === 1;
			if ($skipFirst) {
				$skippers++;
			}
			$pace->next($room, $token, 0);
			$e['started'] = true;
			$answeredLast = false;
			for ($k = 1; $k <= $target; $k++) {
				$pid = $order[$k - 1];
				$poll = $polls[$pid];
				$limit = $timed ? $poll->getTimeLimit() : 0;
				$row = $openRow($token);
				if ($row === null || $row->getPollId() !== $pid) {
					throw new \RuntimeException("Zeile fehlt: {$who['nickname']} Frage $k");
				}
				$perQ[$k - 1]['reached']++;
				$e['k'] = $k;
				$last = $k === $target;
				// Auf der Zielfrage (nicht der letzten) antwortet nur jede zweite Person.
				$answer = !($k === 1 && $skipFirst) && (!$last || $k === $n || $rnd() < 0.5);
				if (!$answer) {
					if ($k === 1 && $skipFirst) {
						$backdate($row, $limit > 0 ? $limit + 1 + (int)floor($rnd() * 20) : 5 + (int)floor($rnd() * 55));
						$e['skipped']++;
					} else {
						$backdate($row, $limit > 0 ? (int)floor($rnd() * $limit * 0.5) : (int)floor($rnd() * 60));
					}
				} else {
					$elapsed = $limit > 0 ? 1 + (int)floor($rnd() * ($limit - 1)) : 3 + (int)floor($rnd() * 57);
					$backdate($row, $elapsed);
					$correct = $rnd() < 0.65;
					$votes->recordVote($room, $token, paceAnswer($poll, $correct, $rnd), false, $pid);
					// Tatsächlich gerechnete Zeit (eine Sekundengrenze kann dazwischen liegen).
					$stored = json_decode($voteMapper->findByPollAndToken($pid, $token)->getPayload(), true);
					$isRight = !empty($stored['correct']);
					$pending = !empty($stored['pending']);
					$e['answered']++;
					$perQ[$k - 1]['answered']++;
					$answeredLast = $k === $n;
					if ($pending) {
						$e['pending']++;
						$perQ[$k - 1]['pending']++;
					}
					if ($isRight) {
						$e['correct']++;
						$perQ[$k - 1]['correct']++;
						$lim = (int)($stored['limit'] ?? 0);
						$el = (int)($stored['elapsed'] ?? 0);
						// Formel wie QuizService::points, hier unabhängig nachgerechnet.
						$e['score'] += $lim <= 0 ? 1000 : (int)round(1000 * (1 - 0.5 * max(0.0, min(1.0, $el / $lim))));
					}
				}
				if (!$last || $done) {
					$pace->next($room, $token, $pid);
				}
			}
			$e['finished'] = $target === $n && ($done || $answeredLast);
			// Offene Zeile? (Nur zur Ansicht — der Beamer zählt, wer fertig ist, nur unter „Finished“.)
			$e['open'] = !($target === $n && $done);
			$expect[] = $e;
		}
		// Alle Stimmen dieses Aufrufs 60 s zurück -> endgültig (fw ist 3 bzw. 6 s).
		foreach (array_chunk(array_column($people, 'token'), 100) as $chunk) {
			$qb = $db->getQueryBuilder();
			$qb->update('pulse_votes')
				->set('created_at', $qb->createFunction('created_at - 60'))
				->where($qb->expr()->in('poll_id', $qb->createNamedParameter($order, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->in('voter_token', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	} else {
		foreach ($people as $who) {
			$expect[] = ['nickname' => $who['nickname'], 'k' => 0, 'started' => false, 'finished' => false,
				'answered' => 0, 'skipped' => 0, 'correct' => 0, 'score' => 0, 'pending' => 0, 'open' => false];
		}
	}
	$questions = [];
	foreach ($perQ as $i => $q) {
		$questions[] = ['k' => $i + 1] + $q;
	}
	return [
		'code' => $room->getCode(),
		'state' => $state,
		'n' => $n,
		'joined' => count($people),
		'started' => count(array_filter($expect, static fn ($e) => $e['started'])),
		'finished' => count(array_filter($expect, static fn ($e) => $e['finished'])),
		'players' => $expect,
		'questions' => $questions,
	];
}

if ($cmd === 'race') {
	$r = paceRace((string)($argv[2] ?? ''), (int)($argv[3] ?? 10), (int)($argv[4] ?? 7));
	echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
	exit(0);
}

/** Die sechs Quiz-Typen des Tempo-Prüfstands (Timer je Frage $limit s). */
function paceDeck(DeckService $deck, $room, int $limit): array {
	return [
		add($deck, $room, ['type' => 'choice', 'question' => 'Which port does HTTPS use by default?',
			'options' => ['80', '443', '8080', '22'], 'correctIndex' => 1, 'timeLimit' => $limit], 'choice'),
		add($deck, $room, ['type' => 'truefalse', 'question' => 'A room code is case sensitive.',
			'correctIndex' => 1, 'timeLimit' => $limit], 'truefalse'),
		add($deck, $room, ['type' => 'multi', 'question' => 'Which of these are databases?',
			'options' => ['Postgres', 'Redis', 'Traefik', 'MariaDB'], 'correctIndexes' => [0, 3], 'timeLimit' => $limit], 'multi'),
		add($deck, $room, ['type' => 'number', 'question' => 'How many question types does Pulse offer?',
			'target' => 9, 'tolerance' => 1, 'timeLimit' => $limit], 'number'),
		add($deck, $room, ['type' => 'text', 'question' => 'Which header blocks framing?',
			'answers' => ['X-Frame-Options'], 'timeLimit' => $limit], 'text'),
		add($deck, $room, ['type' => 'match', 'question' => 'Match protocol to port', 'pairs' => [
			['left' => 'SSH', 'right' => '22'], ['left' => 'HTTPS', 'right' => '443'],
			['left' => 'DNS', 'right' => '53'], ['left' => 'SMTP', 'right' => '25'],
		], 'timeLimit' => $limit], 'match'),
	];
}

/** $count Auswahlfragen (Rennen mit vielen Fragen: mid/wide/big). */
function paceChoices(DeckService $deck, $room, int $count, int $limit): array {
	$pool = [
		['Which port does HTTPS use by default?', ['80', '443', '8080', '22'], 1],
		['Which of these is not a database?', ['PostgreSQL', 'MariaDB', 'Traefik', 'SQLite'], 2],
		['Which protocol resolves host names?', ['DNS', 'SMTP', 'SSH', 'NTP'], 0],
		['Which tool is a reverse proxy?', ['Borg', 'Traefik', 'Redis', 'Loki'], 1],
	];
	$out = [];
	for ($i = 0; $i < $count; $i++) {
		[$q, $opts, $right] = $pool[$i % count($pool)];
		$out[] = add($deck, $room, ['type' => 'choice', 'question' => 'Q' . ($i + 1) . ': ' . $q,
			'options' => $opts, 'correctIndex' => $right, 'timeLimit' => $limit], 'choice-' . ($i + 1));
	}
	return $out;
}

/*
 *   php probe.php pace-create <uid>   -> JSON (run.sh schreibt es nach pace.json)
 *
 * Die Räume des Tempo-Prüfstands (§6.1). `destroy` räumt sie mit weg. Die
 * Reihenfolge der Strecken (pace-mod -> pace-run -> pace-phone -> pace-screen)
 * steht in der Spezifikation; `click` ist nur für Klickstrecken da.
 */
if ($cmd === 'pace-create') {
	$uid = $argv[2] ?? 'pulse-shots';
	$raceProbe = function ($room, int $count, int $seed): array {
		$r = paceRace($room->getCode(), $count, $seed);
		return ['joined' => $r['joined'], 'started' => $r['started'], 'finished' => $r['finished']];
	};
	$make = function (string $label, callable $fill, bool $practice = false) use ($rooms, $pace, $uid) {
		$room = $rooms->createRoom($uid, 'quiz', 'Pace check · ' . $label);
		$polls = $fill($room);
		$room = $pace->setPace($room, 'self');
		if ($practice) {
			$rooms->setPractice($room, true);
		}
		return [$room, $polls];
	};
	$open = function ($room, int $closesIn, ?bool $timed, ?string $feedback) use ($pace) {
		return $pace->openWindow($room, $closesIn > 0 ? time() + $closesIn : 0, $timed, $feedback);
	};
	$three = 3 * 86400;
	$result = [];

	[$room, $polls] = $make('draft', fn ($r) => paceDeck($deck, $r, 30));
	$result['draft'] = ['code' => $room->getCode(), 'polls' => $polls, 'race' => $raceProbe($room, 2, 11)];
	for ($i = 0; $i < 4; $i++) {
		$rooms->heartbeat($room, 'shots-' . $i);
	}

	foreach (['race' => 14, 'click' => 14] as $label => $count) {
		[$room, $polls] = $make($label, fn ($r) => paceDeck($deck, $r, 20));
		$room = $open($room, 0, true, 'each');
		$result[$label] = ['code' => $room->getCode(), 'polls' => $polls, 'race' => $raceProbe($room, $count, $label === 'race' ? 21 : 23)];
		for ($i = 0; $i < 10; $i++) {
			$rooms->heartbeat($room, 'shots-' . $i);
		}
	}

	[$room, $polls] = $make('homework', fn ($r) => paceDeck($deck, $r, 30));
	$room = $open($room, $three, false, 'end');
	$result['homework'] = ['code' => $room->getCode(), 'polls' => $polls, 'race' => $raceProbe($room, 9, 31)];

	[$room, $polls] = $make('e2e', fn ($r) => [
		add($deck, $r, ['type' => 'choice', 'question' => 'Which port does HTTPS use by default?',
			'options' => ['80', '443', '8080', '22'], 'correctIndex' => 1, 'timeLimit' => 20], 'choice'),
		add($deck, $r, ['type' => 'number', 'question' => 'How many question types does Pulse offer?',
			'target' => 9, 'tolerance' => 1, 'timeLimit' => 20], 'number'),
		add($deck, $r, ['type' => 'text', 'question' => 'Which header blocks framing?',
			'answers' => ['X-Frame-Options'], 'timeLimit' => 20], 'text'),
	]);
	$room = $open($room, 0, false, 'each');
	$result['e2e'] = ['code' => $room->getCode(), 'polls' => $polls];

	[$room, $polls] = $make('timed', fn ($r) => [
		add($deck, $r, ['type' => 'choice', 'question' => 'Which port does HTTPS use by default?',
			'options' => ['80', '443', '8080', '22'], 'correctIndex' => 1, 'timeLimit' => 5], 'choice'),
		add($deck, $r, ['type' => 'truefalse', 'question' => 'A room code is case sensitive.',
			'correctIndex' => 1, 'timeLimit' => 5], 'truefalse'),
	]);
	$room = $open($room, 0, true, 'each');
	$result['timed'] = ['code' => $room->getCode(), 'polls' => $polls];

	[$room, $polls] = $make('match8', fn ($r) => [
		add($deck, $r, ['type' => 'match', 'question' => 'Match the service to its default port', 'pairs' => [
			['left' => 'SSH', 'right' => '22'], ['left' => 'HTTPS', 'right' => '443'],
			['left' => 'DNS', 'right' => '53'], ['left' => 'SMTP submission', 'right' => '587'],
			['left' => 'PostgreSQL', 'right' => '5432'], ['left' => 'Redis', 'right' => '6379'],
			['left' => 'NTP', 'right' => '123'], ['left' => 'IMAP over TLS', 'right' => '993'],
		], 'timeLimit' => 60], 'match-8'),
	]);
	$room = $open($room, 0, false, 'each');
	$result['match8'] = ['code' => $room->getCode(), 'polls' => $polls];

	[$room, $polls] = $make('late', fn ($r) => [
		add($deck, $r, ['type' => 'text', 'question' => 'Which header blocks framing?',
			'answers' => ['X-Frame-Options'], 'timeLimit' => 30], 'text'),
	]);
	$room = $open($room, $three, false, 'end');
	$result['late'] = ['code' => $room->getCode(), 'polls' => $polls];

	foreach (['big' => [24, 300, 41], 'mid' => [16, 40, 43], 'wide' => [20, 40, 47]] as $label => [$qs, $count, $seed]) {
		[$room, $polls] = $make($label, fn ($r) => paceChoices($deck, $r, $qs, 20));
		$room = $open($room, 0, true, 'each');
		$result[$label] = ['code' => $room->getCode(), 'polls' => $polls, 'race' => $raceProbe($room, $count, $seed)];
	}

	[$room, $polls] = $make('practice', fn ($r) => paceDeck($deck, $r, 20), true);
	$room = $open($room, 0, null, null);
	$result['practice'] = ['code' => $room->getCode(), 'polls' => $polls];

	echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
	exit(0);
}

if ($cmd === 'destroy') {
	$uid = $argv[2] ?? 'pulse-shots';
	$n = 0;
	foreach ($roomMapper->findByOwner($uid) as $room) {
		$rooms->deleteRoom($room);
		$n++;
	}
	echo "geloescht: $n\n";
	exit(0);
}

fwrite(STDERR, "Unbekannter Befehl: $cmd\n");
exit(1);
