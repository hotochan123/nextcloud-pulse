<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\PlayerMapper;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\ProgressMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IL10N;

/**
 * Demo/test mode (only visible with ?demo=1): synthetic votes for the
 * active question, so that every question type can be checked without a dozen devices.
 * They go through the same normalisation and scoring as real votes and
 * only carry a "demo:" prefix in the token so that they can be removed selectively.
 */
class DemoService {
    /** Prefix of the voter tokens of synthetic votes, upper limit per run. */
    private const DEMO_PREFIX = 'demo:';
    private const DEMO_MAX = 200;

    public function __construct(
        private PollMapper $pollMapper,
        private VoteMapper $voteMapper,
        private PlayerMapper $playerMapper,
        private ProgressMapper $progressMapper,
        private VoteService $voteService,
        private CodeGenerator $codeGenerator,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
    ) {
    }

    // ── Demo/test mode ────────────────────────────────────────────────────────

    /**
     * Creates synthetic votes for the ACTIVE question so that every
     * question type can be checked against a realistic number of participants without
     * operating dozens of devices by hand. The votes go through the same normalisation and
     * tallying as real ones — only their token carries the prefix "demo:" so that
     * clearDemoVotes() can remove them selectively. Status/time barriers
     * are bypassed on purpose (even a paused or expired question can be
     * filled); real votes stay untouched.
     *
     * @return array{seeded:int, total:int}
     * @throws \InvalidArgumentException if no question is active right now
     */
    public function seedDemoVotes(Room $room, int $count): array {
        $count = max(1, min(self::DEMO_MAX, $count));
        $activeId = $room->getActivePollId();
        if ($activeId === 0) {
            throw new \InvalidArgumentException($this->l10n->t('No active question — please show a question first.'));
        }
        try {
            $poll = $this->pollMapper->find($activeId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException($this->l10n->t('No active question.'));
        }

        $isQuiz = $room->getMode() === 'quiz';
        $now = $this->timeFactory->getTime();
        $seeded = 0;
        for ($i = 0; $i < $count; $i++) {
            try {
                $normalized = $this->voteService->normalizeValue($poll, $this->randomDemoValue($poll));
            } catch (\InvalidArgumentException) {
                continue; // a single outlier should not abort the run
            }
            // voter_token is varchar(32): prefix + shortened random part = exactly 32.
            $token = self::DEMO_PREFIX . substr($this->codeGenerator->voterToken(), 0, 32 - strlen(self::DEMO_PREFIX));
            if ($isQuiz) {
                $this->playerMapper->register($room->getId(), $token, $this->demoNickname($i), $now);
                $limit = $poll->getTimeLimit();
                $elapsed = $limit > 0 ? random_int(1, $limit) : random_int(1, 8);
                $payload = json_encode($this->voteService->quizPayload($poll, $normalized, $elapsed));
            } else {
                $payload = json_encode(['value' => $normalized]);
            }
            $vote = new Vote();
            $vote->setPollId($poll->getId());
            $vote->setVoterToken($token);
            $vote->setPayload($payload);
            $vote->setCreatedAt($now + $i); // slightly staggered -> stable sort order
            try {
                $this->voteMapper->insert($vote);
                $seeded++;
            } catch (Exception $e) {
                // rare token collision (UNIQUE) -> skip this one vote
                if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
            }
        }

        return ['seeded' => $seeded, 'total' => $this->voteMapper->countByPoll($poll->getId())];
    }

    /**
     * Removes all demo votes (and demo players along with their self-paced
     * progress) of the room again; real votes and participants are
     * kept.
     *
     * @return array{removed:int}
     */
    public function clearDemoVotes(Room $room): array {
        $removed = 0;
        foreach ($this->pollMapper->findByRoom($room->getId()) as $poll) {
            $removed += $this->voteMapper->deleteDemoByPoll($poll->getId());
        }
        $this->playerMapper->deleteDemoByRoom($room->getId());
        $this->progressMapper->deleteDemoByRoom($room->getId());
        return ['removed' => $removed];
    }

    /** A plausible random value for this question's type (demo/test only). */
    private function randomDemoValue(Poll $poll): mixed {
        $type = $poll->getType();

        if ($type === 'choice' || $type === 'truefalse') {
            $ids = array_column($poll->getOptionsArray(), 'id');
            return $ids ? $ids[array_rand($ids)] : '';
        }
        if ($type === 'multi') {
            $ids = array_column($poll->getOptionsArray(), 'id');
            shuffle($ids);
            $k = random_int(1, max(1, min(count($ids), 3)));
            return array_slice($ids, 0, $k);
        }
        if ($type === 'rank') {
            $ids = array_column($poll->getOptionsArray(), 'id');
            shuffle($ids);
            return $ids;
        }
        if ($type === 'match') {
            // Mostly assign correctly, now and then wrongly — otherwise every row
            // would hold a single bar and the display could not be checked.
            $cfg = $poll->getMatchConfig();
            $targetIds = array_column($cfg['targets'], 'id');
            $solution = $poll->getAnswerKeyArray()['map'] ?? [];
            $out = [];
            foreach ($cfg['items'] as $i => $item) {
                $right = $solution[$item['id']] ?? ($targetIds[$i] ?? '');
                $out[$item['id']] = (random_int(0, 99) < 65 && $right !== '')
                    ? $right
                    : $targetIds[array_rand($targetIds)];
            }
            return $out;
        }
        if ($type === 'number') {
            $key = $poll->getAnswerKeyArray();
            if (isset($key['target'])) {
                $target = (float)$key['target'];
                $tol = max(1.0, (float)($key['tolerance'] ?? 0));
                // mostly close to the target, occasionally clearly off -> a telling distribution
                $spread = $tol * (random_int(0, 99) < 70 ? 1.0 : 4.0);
                return (int)round($target + $this->demoGauss() * $spread);
            }
            return random_int(1, 100);
        }
        if ($type === 'text') {
            $accepted = $poll->getAnswerKeyArray()['accepted'] ?? [];
            // Quiz free text: sometimes an accepted answer, sometimes a scatter.
            if ($accepted && random_int(0, 99) < 60) {
                return (string)$accepted[array_rand($accepted)];
            }
            $words = $this->demoWords();
            return $words[array_rand($words)];
        }
        if ($type === 'scale') {
            $cfg = $poll->getScaleConfig();
            $mode = $cfg['mode'] ?? 'single';
            if ($mode === 'spectrum') {
                $out = [];
                foreach ($cfg['aspects'] as $asp) {
                    $out[$asp['id']] = $this->demoBell((int)$cfg['min'], (int)$cfg['max']);
                }
                return $out;
            }
            if ($mode === 'compass') {
                $r = (int)$cfg['range'];
                return ['x' => $this->demoBell(-$r, $r), 'y' => $this->demoBell(-$r, $r)];
            }
            return $this->demoBell((int)$cfg['min'], (int)$cfg['max']);
        }

        // words: 1..min(maxWords,3) distinct terms
        $n = random_int(1, max(1, min($poll->getMaxWords(), 3)));
        $pool = $this->demoWords();
        shuffle($pool);
        return array_slice($pool, 0, $n);
    }

    /** Rough standard normal distribution (~N(0,1)) from the sum of three uniforms. */
    private function demoGauss(): float {
        return (random_int(0, 1000) + random_int(0, 1000) + random_int(0, 1000)) / 1000.0 - 1.5;
    }

    /** Integer from [min..max], concentrated towards the middle (gives a nice bell curve). */
    private function demoBell(int $min, int $max): int {
        if ($max <= $min) {
            return $min;
        }
        $mid = ($min + $max) / 2.0;
        $span = ($max - $min) / 2.0;
        $v = (int)round($mid + $this->demoGauss() / 1.5 * $span);
        return max($min, min($max, $v));
    }

    /**
     * Word pool for demo word clouds/free text and display names for demo players
     * (quiz leaderboard, hard to mix up). Methods instead of constants, because both
     * end up on the projector and therefore have to go through translation.
     *
     * The words describe an impression of something new — mixed, not
     * just praise. Previously this held value terms ("Trust", "Future",
     * "Solidarity"); on the projector they read like a mission statement rather
     * than feedback, and the demo is meant to show what the word cloud is
     * used for.
     *
     * @return list<string>
     */
    private function demoWords(): array {
        return [
            $this->l10n->t('Faster'), $this->l10n->t('Cleaner'), $this->l10n->t('Cluttered'),
            $this->l10n->t('Familiar'), $this->l10n->t('Snappy'), $this->l10n->t('Confusing'),
            $this->l10n->t('Polished'), $this->l10n->t('Dense'), $this->l10n->t('Intuitive'),
            $this->l10n->t('Busy'), $this->l10n->t('Lighter'), $this->l10n->t('Crowded'),
            $this->l10n->t('Clear'), $this->l10n->t('Bold'), $this->l10n->t('Flat'),
            $this->l10n->t('Quiet'),
        ];
    }

    /** @return list<string> */
    private function demoNames(): array {
        return [
            $this->l10n->t('Blue whale'), $this->l10n->t('Red fox'), $this->l10n->t('Sea eagle'),
            $this->l10n->t('Hare'), $this->l10n->t('Stone marten'), $this->l10n->t('Peregrine falcon'),
            $this->l10n->t('Lynx'), $this->l10n->t('Beaver'), $this->l10n->t('Hedgehog'),
            $this->l10n->t('Badger'), $this->l10n->t('Otter'), $this->l10n->t('Crane'),
            $this->l10n->t('Eagle owl'), $this->l10n->t('Kingfisher'), $this->l10n->t('Roe deer'),
            $this->l10n->t('Wild boar'), $this->l10n->t('Marmot'), $this->l10n->t('Salamander'),
            $this->l10n->t('Goldfinch'), $this->l10n->t('Dormouse'),
        ];
    }

    /** Unique, friendly display name for the i-th demo player. */
    private function demoNickname(int $i): string {
        $names = $this->demoNames();
        $count = count($names);
        $base = $names[$i % $count];
        $round = intdiv($i, $count);
        return $round > 0 ? $base . ' ' . ($round + 1) : $base;
    }
}
