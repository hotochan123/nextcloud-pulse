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
 * Demo-/Testmodus (nur mit ?demo=1 sichtbar): synthetische Stimmen für die
 * aktive Frage, damit sich jeder Fragetyp ohne ein Dutzend Geräte prüfen lässt.
 * Sie laufen durch dieselbe Normalisierung und Wertung wie echte Stimmen und
 * tragen nur ein "demo:"-Präfix im Token, damit sie gezielt wieder verschwinden.
 */
class DemoService {
    /** Präfix der Voter-Token synthetischer Stimmen, Obergrenze je Lauf. */
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

    // ── Demo-/Testmodus ───────────────────────────────────────────────────────

    /**
     * Erzeugt synthetische Stimmen für die AKTIVE Frage, damit sich jeder
     * Fragetyp gegen eine realistische Teilnehmerzahl prüfen lässt, ohne von Hand
     * zig Geräte zu bedienen. Die Stimmen durchlaufen dieselbe Normalisierung und
     * Auszählung wie echte — nur ihr Token trägt das Präfix „demo:", damit
     * clearDemoVotes() sie gezielt wieder entfernen kann. Status-/Zeitschranken
     * werden bewusst übergangen (auch eine pausierte oder abgelaufene Frage lässt
     * sich befüllen); echte Stimmen bleiben unberührt.
     *
     * @return array{seeded:int, total:int}
     * @throws \InvalidArgumentException wenn gerade keine Frage aktiv ist
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
                continue; // ein einzelner Ausreißer soll den Lauf nicht abbrechen
            }
            // voter_token ist varchar(32): Präfix + gekürzter Zufallsteil = genau 32.
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
            $vote->setCreatedAt($now + $i); // leicht gestaffelt -> stabile Sortierung
            try {
                $this->voteMapper->insert($vote);
                $seeded++;
            } catch (Exception $e) {
                // seltene Token-Kollision (UNIQUE) -> diese eine Stimme überspringen
                if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
            }
        }

        return ['seeded' => $seeded, 'total' => $this->voteMapper->countByPoll($poll->getId())];
    }

    /**
     * Entfernt alle Demo-Stimmen (und Demo-Spieler samt ihrem Fortschritt im
     * eigenen Tempo) des Raums wieder; echte Stimmen und Teilnehmende bleiben
     * erhalten.
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

    /** Ein plausibler Zufallswert für den Typ dieser Frage (nur Demo/Test). */
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
            // Meist richtig zuordnen, ab und zu daneben — sonst stünde in jeder
            // Zeile ein einziger Balken und die Darstellung wäre nicht prüfbar.
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
                // meist nah am Ziel, gelegentlich klar daneben -> anschauliche Verteilung
                $spread = $tol * (random_int(0, 99) < 70 ? 1.0 : 4.0);
                return (int)round($target + $this->demoGauss() * $spread);
            }
            return random_int(1, 100);
        }
        if ($type === 'text') {
            $accepted = $poll->getAnswerKeyArray()['accepted'] ?? [];
            // Quiz-Freitext: mal eine akzeptierte Antwort, mal eine Streuung.
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

        // words: 1..min(maxWords,3) verschiedene Begriffe
        $n = random_int(1, max(1, min($poll->getMaxWords(), 3)));
        $pool = $this->demoWords();
        shuffle($pool);
        return array_slice($pool, 0, $n);
    }

    /** Grobe Standardnormalverteilung (~N(0,1)) über die Summe dreier Uniformen. */
    private function demoGauss(): float {
        return (random_int(0, 1000) + random_int(0, 1000) + random_int(0, 1000)) / 1000.0 - 1.5;
    }

    /** Ganzzahl aus [min..max], zur Mitte hin verdichtet (ergibt eine schöne Glocke). */
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
     * Wortvorrat für Demo-Wortwolken/Freitext und Anzeigenamen für Demo-Spieler
     * (Quiz-Rangliste, verwechslungsarm). Methoden statt Konstanten, weil beide
     * auf dem Beamer landen und daher durch die Übersetzung müssen.
     *
     * Die Wörter beschreiben einen Eindruck von etwas Neuem — gemischt, nicht
     * nur Lob. Vorher standen hier Werte-Begriffe („Vertrauen", „Zukunft",
     * „Solidarität"); die lasen sich auf dem Beamer wie ein Leitbild und nicht
     * wie eine Rückmeldung, und die Demo soll zeigen, wofür man die Wortwolke
     * benutzt.
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

    /** Eindeutiger, freundlicher Anzeigename für den i-ten Demo-Spieler. */
    private function demoNickname(int $i): string {
        $names = $this->demoNames();
        $count = count($names);
        $base = $names[$i % $count];
        $round = intdiv($i, $count);
        return $round > 0 ? $base . ' ' . ($round + 1) : $base;
    }
}
