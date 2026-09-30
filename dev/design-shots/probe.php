<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Test-bench data for the design screenshots.
 *
 * Runs INSIDE the Nextcloud container (needs lib/base.php) and creates two rooms
 * with one question per type each, fills them with votes via the real demo path
 * and switches their state on request. `destroy` removes everything again.
 *
 *   php probe.php create <uid>
 *   php probe.php state  <code> <pollId> open|locked
 *   php probe.php seed   <code> <n>
 *   php probe.php end    <code>
 *   php probe.php destroy <uid>
 *   php probe.php add-words <code> <label> [maxWords]
 *
 * Self-paced quiz (stage 4):
 *   php probe.php pace <code> live|self
 *   php probe.php open <code> [closesIn] [timed 0|1] [feedback each|end]
 *   php probe.php close|release <code>
 *   php probe.php lock <code> 0|1
 *   php probe.php window <code> closesAt=<ts|now±s> closedAt=… releasedAt=…
 *   php probe.php race <code> <N> [seed]
 *   php probe.php remove <code> <name>
 *   php probe.php reset <code>
 *   php probe.php pace-create <uid>
 *
 * Section references (§…) point to the design notes of the redesign and of the
 * self-paced quiz, which are not in the public repository (see "References in
 * code comments" in the README).
 */

// Command line only: in a git checkout inside the web root, the web server
// would otherwise run it for anyone who asks (.htaccess is the other guard).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
 * An image without an external file: GD paints a small bar chart. The white
 * background is deliberate — it is exactly what glares in dark mode when the
 * backing surface is missing (§7.8). The shape picks the test case: landscape, portrait or panorama.
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

/** Attach an image to a question (GD -> the app's upload path). */
function attachImage(PollImageService $images, PollMapper $pollMapper, int $pollId, int $w, int $h): void {
	$tmp = tempnam(sys_get_temp_dir(), 'shot');
	file_put_contents($tmp, makeImage($w, $h));
	$images->store($pollMapper->find($pollId), [
		'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK,
	]);
	@unlink($tmp);
}

/** Append a question and return its ID + type. */
function add(DeckService $deck, $room, array $data, string $label): array {
	$poll = $deck->addPoll($room, $data);
	return ['id' => $poll->getId(), 'type' => $data['type'], 'label' => $label];
}

if ($cmd === 'create') {
	$uid = $argv[2] ?? 'pulse-shots';

	// Poll room: every type without a solution.
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

	// An image on the first question — the image question from §7.1 (landscape, 3:2).
	attachImage($images, $pollMapper, $pollPolls[0]['id'], 900, 600);

	// Quiz room: every quiz type with a solution.
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

	// Edge-case room (§2.6, test cases from §11): row cap with two options,
	// shrink loop with eight, a two-line label — and a test phrase with
	// g, p, q, ü, ß that makes clipped descenders visible at once.
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
			// German on purpose: the English test sentence lacks the descenders
			// g, p, q, ü, ß, and exactly those are what gets clipped.
			'question' => 'Prüfung: gequälte Unterlängen bei Übergaben groß genug für die Rückgängig-Frage?',
			'options' => [
				'Rückgängig gemachte Änderungen später gequält prüfen und übergeben',
				'Übergabe gleich groß aufsetzen',
				'Später prüfen',
			],
		], 'long-label'),
	];

	// Question-type edge cases (§7.10/§11): constellations that do not occur in
	// the existing data and without which the sign-off of stage 3 cannot be backed up.
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
		// The editor does not allow two aspects (at least three, and §13 forbids
		// changing that) — so the smallest possible radar is what gets tested.
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
		// Matching with eight pairs: the existing data has only four, and only from
		// about six rows on does it show whether the projector and the moderator view
		// can carry it. Labels are deliberately of different lengths.
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

	// Eight options WITH an image: the test case from §11 for the fold rule.
	attachImage($images, $pollMapper, $edgePolls[1]['id'], 900, 600);

	// The same matching as a quiz: here the solution colour comes in, and the
	// moderator view shows the private panel next to the canvas.
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

	// A room without a single question (§9.8): the presentation view has to carry
	// it — "No question yet" plus "Add a question" as the main action.
	$emptyRoom = $rooms->createRoom($uid, 'quiz', 'Design check · empty');

	// Final-standings edge cases: nine participants (places 4–8 plus "+N more") and
	// two (the right column disappears, podium centred).
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
 * Fake presence: N heartbeats with made-up tokens. The incoming-answers display
 * puts the answers received in relation to that ("12 of 24"), and without
 * real phones it would always read "0" there. The window is short (15 s) — so
 * the screenshot has to be taken right afterwards.
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
 * Set the countdown to a given remaining time: the start time moves back
 * far enough that exactly <seconds> are left. There is no other way to
 * photograph the urgency stage (below 10 s) reproducibly.
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
 * Deterministic votes for the constellations from §7.10. Randomness is no good
 * here: "mean ≈ median" or "all votes on one value" are exactly the cases
 * a random distribution never hits, and the heatmap threshold has to be
 * photographable at 44 and at 46 answers, not "roughly".
 *
 *   php probe.php fixture <code> <pollId> scale-same|scale-one|compass|rank-polar|words [n]
 *   php probe.php fixture <code> <pollId> words-long [n] [word]
 */
if ($cmd === 'fixture') {
    $room = $roomMapper->findByCode((string)$argv[2]);
    $poll = $pollMapper->find((int)$argv[3]);
    $kind = (string)($argv[4] ?? '');
    $arg = (int)($argv[5] ?? 0);
    $voteMapper = \OCP\Server::get(\OCA\Pulse\Db\VoteMapper::class);
    $now = time();
    $i = 0;
    // Continues from the existing votes: a second call adds votes instead of
    // failing on token uniqueness (44 answers, then 46).
    $base = $voteMapper->countByPoll($poll->getId());
    $put = function (mixed $value) use ($voteMapper, $poll, $now, $base, &$i): void {
        $vote = new \OCA\Pulse\Db\Vote();
        $vote->setPollId($poll->getId());
        // "demo:" prefix -> the same clean-up as synthetic votes.
        $vote->setVoterToken('demo:fix-' . $poll->getId() . '-' . ($base + $i));
        $vote->setPayload(json_encode(['value' => $value]));
        $vote->setCreatedAt($now + $i);
        $voteMapper->insert($vote);
        $i++;
    };

    if ($kind === 'scale-same') {
        // Symmetric around 4: mean 4.0 and median 4 coincide — the two figures
        // sit on top of each other and must still not overlap.
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
        // Own random generator (LCG) instead of rand(): the same call yields
        // the same point cloud, otherwise comparing 44 against 46 would be worthless.
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
        // One item with many first AND many last places: exactly the case
        // that the average alone makes invisible (§7.5).
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
        // A fixed distribution instead of randomness: the word cloud on the store page
        // should look the same on every run. A random run hits neither the gradation
        // of font sizes nor a decent number of different words.
        // The same terms as DemoService::demoWords().
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
    } elseif ($kind === 'words-long') {
        // One word of 40 characters, the most AnswerRules keeps, <n> times (default
        // once). On top of `words` it sits inside a full cloud; with enough
        // mentions it is the largest word. The shots pass the word in: the phone
        // pass types the same one, so its own answer and these add up.
        $word = (string)($argv[6] ?? 'Kraftfahrzeughaftpflichtversicherungsamt');
        for ($k = 0; $k < max(1, $arg); $k++) {
            $put([$word]);
        }
    } else {
        fwrite(STDERR, "Unbekannte Fixture: $kind\n");
        exit(1);
    }
    echo "ok: $i\n";
    exit(0);
}

/*
 * A word-cloud question appended to a room when a pass needs it, not in
 * `create`: every other pass keeps exactly the rooms and decks it had (the room
 * list shows the number of questions). Prints the question like `create` does.
 */
if ($cmd === 'add-words') {
    $room = $roomMapper->findByCode((string)$argv[2]);
    $poll = add($deck, $room, [
        'type' => 'words',
        'question' => 'One word: what slowed the project down?',
        'maxWords' => max(1, (int)($argv[4] ?? 3)),
    ], (string)($argv[3] ?? 'words'));
    echo json_encode($poll, JSON_UNESCAPED_UNICODE), "\n";
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
 * One person's answer to a quiz question: either the solution or any
 * other option. Both types in the store quiz keep their options with
 * IDs and carry the solution as an ID in correctOption.
 */
function storeAnswer($poll, bool $correct, int $seed): string {
	$ids = array_map('strval', array_column($poll->getOptionsArray(), 'id'));
	$right = (string)$poll->getCorrectOption();
	if ($correct) { return $right; }
	$wrong = array_values(array_filter($ids, static fn ($id) => $id !== $right));
	// The wrong votes are spread out, otherwise the panel shows two bars
	// and three zeros — on a promotional image that looks like a mock-up.
	return $wrong === [] ? $right : $wrong[$seed % count($wrong)];
}

/*
 * Template for the store-page screenshots. Unlike `create`, this is not
 * about test cases but about images that someone reads without prior knowledge:
 * few rooms, self-explanatory questions, round numbers.
 *
 * The leaderboard is deliberately NOT filled via the demo path. That path
 * issues a new token per vote and so registers new players per question — the
 * podium then shows the same name twice with "shared" next to it, and with
 * more votes than names a "2" is appended. Instead, twelve people with a
 * fixed token answer every question here.
 */
if ($cmd === 'store') {
	$uid = $argv[2] ?? 'pulse-shots';

	// Poll room: the three types you understand at a glance.
	$pollRoom = $rooms->createRoom($uid, 'poll', 'Team workshop');
	$pollPolls = [
		add($deck, $pollRoom, [
			'type' => 'words',
			'question' => 'One word: how does the new dashboard feel?',
			// Really one word: the question says so, so the app should enforce it
			// too. As a side effect every vote is exactly one mention, and the
			// numbers under the cloud add up.
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

	// Quiz room: only types with an unambiguous solution, so the leaderboard is right.
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
	 * Twelve players, each person with a fixed token across all five questions.
	 *
	 * Who gets which question right is written out as a pattern (1 = correct)
	 * instead of a formula: only that way does every single question have a
	 * distribution worth looking at — "all correct" would be a single bar at
	 * 100 % in the picture. The rows are sorted by hits and the answer time grows
	 * with the index, so score and order are unambiguous and the podium shows
	 * three different names without shared places.
	 */
	$voteMapper = \OCP\Server::get(\OCA\Pulse\Db\VoteMapper::class);
	$playerMapper = \OCP\Server::get(\OCA\Pulse\Db\PlayerMapper::class);
	$answers = \OCP\Server::get(\OCA\Pulse\Service\AnswerRules::class);
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
			$normalized = $answers->normalizeValue($poll, $raw);
			$elapsed = 2 + $i;
			$vote = new \OCA\Pulse\Db\Vote();
			$vote->setPollId($poll->getId());
			$vote->setVoterToken($token);
			$vote->setPayload((string)json_encode($answers->quizPayload($poll, $normalized, $elapsed)));
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
 * ── Self-paced quiz (stage 4, §6.1) ──────────────────────────────────────
 *
 * Everything goes through the real services (PaceService, VoteService, mappers),
 * so the captures only show states the engine really produces. The only raw
 * parts are `window` (timestamps written directly, an expired deadline without
 * waiting) and backdating the start and vote times in `race`.
 */
$pace = \OCP\Server::get(\OCA\Pulse\Service\PaceService::class);

/** Room by code, or abort with a message (no stack trace in the test-bench log). */
function paceRoom(RoomMapper $roomMapper, string $code) {
	try {
		return $roomMapper->findByCode($code);
	} catch (\Throwable $e) {
		fwrite(STDERR, "Raum nicht gefunden: $code\n");
		exit(1);
	}
}

/** Window state after an action, one line of JSON. */
function paceWindow($room, PollMapper $pollMapper): string {
	return json_encode(\OCA\Pulse\Service\PaceService::windowView($room, time(), count($pollMapper->findByRoom($room->getId()))));
}

/** Service call; domain errors (400/409 in the controller) as a message, exit 1. */
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
 * closesIn = seconds from now, 0 = no deadline (otherwise ≥ 60, says the server).
 * If timed/feedback is missing, the server's default applies (no deadline: race,
 * with a deadline: homework).
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
 * Writes the timestamps raw (for test cases only): e.g. closesAt=now-1 makes
 * a deadline expire immediately, without anyone waiting.
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
 * Closes the person's open question without starting the next one — like a
 * /next that broke off between closing and starting (for test cases only). The
 * phone then shows "Your quiz continues.", and /next {after} heals it.
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

// Reset like "Reset quiz" (under the room lock, but without the controller's
// "close first" guard — the test bench may do this while the quiz is open).
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
 * Presence of the people themselves (valid for 15 s): they then show up in /progress as
 * "online". `finished` = only those who are done — the "Everyone still here is through" status line.
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

/** Deterministic randomness (the same LCG as `fixture compass`). */
function paceRandom(int $seed): callable {
	$state = $seed & 0x7fffffff;
	return function () use (&$state): float {
		$state = ($state * 1103515245 + 12345) % 2147483648;
		return $state / 2147483648;
	};
}

/**
 * The raw value of an answer as a phone sends it to /vote: the solution or a
 * deliberately wrong one. A wrong free text = one of three groups that are
 * neither accepted nor rejected -> "Being checked" (pending).
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
			// drop one correct option and add a wrong one — never empty
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
 * N people join (VoteService::quizJoin — the service does not know the
 * join limit of 120 per IP, an HTTP seed would run into it). If the
 * window is open, they get going: ~15 % stay unstarted, the rest go via
 * PaceService::next up to their target question, ~30 % to the end (half of
 * them also tap "I’m done"). Before every answer the open row is backdated by
 * a time within the limit, then VoteService answers (points by the real
 * formula). Two people skip question 1 (with a timer only after the time is
 * up — otherwise /next would be a no-op because of the preview lock).
 * Finally all votes of this call go back by 60 s: that makes them
 * final, and the numbers are settled at once.
 *
 * Output: the expected numbers (per person and per question) as JSON — for
 * cross-checking against /progress and /state?spectate=1.
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

	// Fixed list of names; free names in order (also on a second call).
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
	// Backdate the row (raw): that way the person's clock has already been running for a while.
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
			$done = $toEnd && $rnd() < 0.5; // tapped "I’m done"
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
				// On the target question (unless it is the last one) only every second person answers.
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
					// The time actually counted (a second boundary may lie in between).
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
						// Formula as in QuizService::points, recomputed independently here.
						$e['score'] += $lim <= 0 ? 1000 : (int)round(1000 * (1 - 0.5 * max(0.0, min(1.0, $el / $lim))));
					}
				}
				if (!$last || $done) {
					$pace->next($room, $token, $pid);
				}
			}
			$e['finished'] = $target === $n && ($done || $answeredLast);
			// Open row? (For display only — the projector counts who is done only under "Finished".)
			$e['open'] = !($target === $n && $done);
			$expect[] = $e;
		}
		// All votes of this call back by 60 s -> final (fw is 3 or 6 s).
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

/** The six quiz types of the pace test bench (timer $limit s per question). */
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

/** $count choice questions (races with many questions: mid/wide/big). */
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
 *   php probe.php pace-create <uid>   -> JSON (run.sh writes it to pace.json)
 *
 * The rooms of the pace test bench (§6.1). `destroy` removes them too. The
 * order of the runs (pace-mod -> pace-run -> pace-phone -> pace-screen)
 * is given in the spec (not in the public repository); `click` exists only for click-through runs.
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
