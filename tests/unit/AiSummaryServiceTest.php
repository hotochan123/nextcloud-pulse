<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\AiSummaryController;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Db\Vote;
use OCA\Pulse\Db\VoteMapper;
use OCA\Pulse\Service\AiDraftException;
use OCA\Pulse\Service\AiSummaryService;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\FilesExportException;
use OCA\Pulse\Service\FilesExportService;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\ModerationService;
use OCA\Pulse\Service\NotOwnerException;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomService;
use OCA\Pulse\Service\StateService;
use OCA\Pulse\Service\TallyService;
use OCA\Pulse\Service\VoteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * AI summary of the results overview (stubbed task processing, real tally
 * and filter): what goes to the AI (never names, never hidden answers), how
 * its answer is read, that every number comes from Pulse, that the Markdown
 * carries nothing live of the participants, and who may start, read and save.
 */
#[CoversClass(AiSummaryService::class)]
#[CoversClass(AiSummaryController::class)]
class AiSummaryServiceTest extends TestCase {
    use AiPolicyStub;

    /** The policy of service() and moderation(); null = allow everything. */
    private ?\OCA\Pulse\Service\AiPolicy $policy = null;

    private IManager&MockObject $tasks;
    private Room $room;
    /** @var array<int, Poll> deck in order */
    private array $polls = [];
    /** @var array<int, list<Vote>> */
    private array $votes = [];
    /** @var list<string> */
    private array $types = ['core:text2text'];
    private bool $aiHelp = true;
    /** @var list<Task> */
    private array $scheduled = [];
    /** @var array<int, Task> */
    private array $stored = [];
    /** @var list<array> */
    private array $leaderboard = [];
    private int $leaderboardCalls = 0;
    private IL10N&MockObject $l10n;

    protected function setUp(): void {
        $this->room = new Room();
        $this->room->setId(5);
        $this->room->setCode('ABCDEF');
        $this->room->setOwnerUid('alice');
        $this->room->setMode('quiz');
        $this->room->setTitle('Secret room');

        $this->tasks = $this->createMock(IManager::class);
        $this->tasks->method('getAvailableTaskTypeIds')->willReturnCallback(fn () => $this->types);
        $this->tasks->method('scheduleTask')->willReturnCallback(function (Task $t): void {
            $t->setId(71 + count($this->scheduled));
            $this->scheduled[] = $t;
        });
        $this->tasks->method('getUserTask')->willReturnCallback(function (int $id, ?string $uid): Task {
            if ($uid !== 'alice' || !isset($this->stored[$id])) {
                throw new \OCP\TaskProcessing\Exception\NotFoundException('no');
            }
            return $this->stored[$id];
        });

        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf($s, $p));
        $this->l10n->method('n')->willReturnCallback(fn (string $one, string $many, int $n) => str_replace('%n', (string)$n, $n === 1 ? $one : $many));
        $this->l10n->method('getLocaleCode')->willReturn('en');
    }

    // ── What goes to the AI ─────────────────────────────────────────────────

    public function testPromptHoldsQuestionsAndVisibleAnswersButNoNamesAndNoHiddenAnswers(): void {
        $this->deckWithEveryKind();

        $data = $this->service()->start($this->room, 'alice');

        $this->assertSame(['task' => 71], $data);
        $this->assertCount(1, $this->scheduled);
        $task = $this->scheduled[0];
        $this->assertSame('alice', $task->getUserId());
        $this->assertSame('pulse', $task->getAppId());
        $this->assertSame('pulse-summary:ABCDEF', $task->getCustomId());
        $prompt = $task->getInput()['input'];
        $this->assertStringContainsString('Question 2: What did you like?', $prompt);
        $this->assertStringContainsString('- The pizza (×2)', $prompt);
        $this->assertStringContainsString('Question 3: One word', $prompt);
        $this->assertStringContainsString('- apple (×2)', $prompt);
        $this->assertStringContainsString('<<<BEGIN RESULTS>>>', $prompt);
        $this->assertStringContainsString('same language as the questions', $prompt);
        foreach (['rude thing', 'secretword', 'Nicky', 'tok-', 'Secret room', 'ABCDEF', 'Pick one', 'Paris'] as $never) {
            $this->assertStringNotContainsStringIgnoringCase($never, $prompt, $never . ' must not go to the AI');
        }
    }

    public function testAnswersAreCutPerAnswerPerQuestionAndInTotal(): void {
        $short = [];
        $long = [];
        for ($i = 0; $i < 150; $i++) {
            $short[] = ['sample' => 'answer ' . $i, 'count' => 1];
            $long[] = ['sample' => sprintf('%03d', $i) . str_repeat('x', 400), 'count' => 1];
        }
        $poll = function (string $q): Poll {
            $p = new Poll();
            $p->setType('text');
            $p->setQuestion($q);
            return $p;
        };
        $results = [
            ['poll' => $poll("Q1\nsecond line"), 'tally' => ['answers' => $short, 'total' => 150], 'rows' => []],
            ['poll' => $poll('Q2'), 'tally' => ['answers' => $long, 'total' => 150], 'rows' => []],
            ['poll' => $poll('Q3'), 'tally' => ['answers' => $short, 'total' => 150], 'rows' => []],
        ];

        $blocks = AiSummaryService::blocks($results);

        $this->assertSame([1, 2], array_keys($blocks), 'the third question no longer fits into 20 000 characters');
        $this->assertCount(100, $blocks[1]['answers'], 'the 100 most frequent answers');
        $this->assertSame('Q1 second line', $blocks[1]['question'], 'one line per question');
        $this->assertSame(300, mb_strlen($blocks[2]['answers'][0][0]), 'each answer cut to 300 characters');
        $this->assertLessThan(100, count($blocks[2]['answers']), 'the second question is cut where the total is reached');
        $material = substr(AiSummaryService::prompt($blocks), strpos(AiSummaryService::prompt($blocks), '<<<BEGIN RESULTS>>>'));
        $this->assertLessThanOrEqual(20000 + 100, mb_strlen($material));
        $this->assertGreaterThan(19000, mb_strlen($material));
    }

    // ── Reading the AI's answer ─────────────────────────────────────────────

    public function testParseReadsJsonInACodeFenceAndKeepsOnlySentQuestions(): void {
        $out = "Sure!\n```json\n{\"questions\":[{\"n\":2,\"points\":[\"A\",\" a \",\"\",\"B\\nline\",7,\"C\",\"D\",\"E\",\"F\"]},{\"n\":9,\"points\":[\"x\"]},{\"n\":\"3\",\"points\":[\"y\"]}]}\n```";

        $points = AiSummaryService::parse($out, [2, 3]);

        $this->assertSame([2 => ['A', 'B line', 'C', 'D', 'E']], $points);
        $this->assertSame(300, mb_strlen(AiSummaryService::parse('{"questions":[{"n":1,"points":["' . str_repeat('z', 500) . '"]}]}', [1])[1][0]));
    }

    public function testParseOfGarbageIsEmpty(): void {
        foreach (['', 'no json', '{"questions":"x"}', '[1,2]', '{"questions":[{"n":1,"points":[]}]}'] as $out) {
            $this->assertSame([], AiSummaryService::parse($out, [1]), $out);
        }
    }

    // ── The Markdown ───────────────────────────────────────────────────────

    public function testNumbersComeFromPulseAndThePointsFromTheAi(): void {
        $this->deckWithEveryKind();
        $this->leaderboard = [
            ['rank' => 1, 'nickname' => 'Nicky', 'score' => 1200],
            ['rank' => 2, 'nickname' => 'Bo', 'score' => 800],
            ['rank' => 2, 'nickname' => 'Cy', 'score' => 800],
            ['rank' => 4, 'nickname' => 'Dee', 'score' => 1],
        ];
        $this->stored[71] = $this->task(71, Task::STATUS_SUCCESSFUL,
            '{"questions":[{"n":2,"points":["Most liked the pizza","100% loved it"]},{"n":3,"points":["Fruit wins"]}]}');

        $md = $this->service()->status($this->room, 'alice', 71)['text'];

        $this->assertStringStartsWith("# Secret room – Summary 2026-11-05\n", $md);
        $this->assertStringContainsString("## 1. Pick one (Multiple choice)\n\n- Paris: 3 (75%)\n- Rome: 1 (25%)\n", $md);
        $this->assertStringContainsString("## 2. What did you like? (Free text)\n\n- 4 answers\n- The pizza: 2 (50%)\n- Music: 1 (25%)\n", $md);
        $this->assertStringContainsString("**Key points (AI)**\n\n- Most liked the pizza\n- 100% loved it\n", $md);
        $this->assertStringContainsString("- 3 answers\n- apple: 2", $md);
        $this->assertStringContainsString("- Fruit wins\n", $md);
        $this->assertStringContainsString("## Leaderboard\n\n1. Nicky – 1200 points\n2. Bo – 800 points\n2. Cy – 800 points\n", $md);
        $this->assertStringNotContainsString('Dee', $md, 'top 3 only');
        $this->assertStringNotContainsString('rude thing', $md, 'hidden answers stay out');
        $this->assertStringNotContainsString('secretword', $md, 'hidden words stay out');
    }

    public function testAPollHasNoLeaderboardAndAQuestionWithoutAnswersNoList(): void {
        $this->room->setMode('poll');
        $this->addPoll('words', 'Empty cloud');

        $data = $this->service()->start($this->room, 'alice');

        $this->assertSame('done', $data['status'], 'nothing to ask the AI');
        $this->assertSame([], $this->scheduled);
        $this->assertStringContainsString("## 1. Empty cloud (Word cloud)\n\n- 0 answers\n", $data['text']);
        $this->assertStringNotContainsString('(no answers)', $data['text']);
        $this->assertStringNotContainsString('Leaderboard', $data['text']);
        $this->assertSame(0, $this->leaderboardCalls);
    }

    public function testPracticeRunHasNoLeaderboard(): void {
        $this->room->setPractice(true);
        $this->addPoll('choice', 'Pick one', [['id' => 'AA', 'label' => 'Paris']]);
        $this->leaderboard = [['rank' => 1, 'nickname' => 'Nicky', 'score' => 5]];

        $this->assertStringNotContainsString('Nicky', $this->service()->start($this->room, 'alice')['text']);
    }

    public function testParticipantAndAiTextIsEscaped(): void {
        $this->room->setTitle('*Quiz*');
        $poll = $this->addPoll('text', 'Links? <script>');
        $this->votes[$poll->getId()] = [$this->vote(1, '![x](http://evil/a.png)'), $this->vote(2, "[click](javascript:alert(1))\n# Head")];
        $this->leaderboard = [['rank' => 1, 'nickname' => '<b>x</b>', 'score' => 1]];
        $this->stored[71] = $this->task(71, Task::STATUS_SUCCESSFUL, '{"questions":[{"n":1,"points":["see <img src=x onerror=alert(1)> and `code`"]}]}');

        $md = $this->service()->status($this->room, 'alice', 71)['text'];

        $this->assertStringContainsString('# \*Quiz\* – Summary', $md);
        $this->assertStringContainsString('Links? \<script\>', $md);
        $this->assertStringContainsString('- !\[x\](http://evil/a.png): 1', $md);
        $this->assertStringContainsString('- \[click\](javascript:alert(1)) # Head: 1', $md);
        $this->assertStringContainsString('1. \<b\>x\</b\> – 1 point', $md);
        $this->assertStringContainsString('- see \<img src=x onerror=alert(1)\> and \`code\`', $md);
        $this->assertDoesNotMatchRegularExpression('/(?<!\\\\)[<\[`]/', $md, 'no unescaped HTML, link or code');
    }

    // ── Start, status, cancel ──────────────────────────────────────────────

    public function testSwitchOffIs409AndNothingGoesOut(): void {
        $this->deckWithEveryKind();
        $this->aiHelp = false;
        $this->startFails(409, 'AI help');
    }

    public function testNoProviderIs503(): void {
        $this->deckWithEveryKind();
        $this->types = [];
        $this->startFails(503, 'AI is not available.');
    }

    // ── The admin's rules (AiPolicy) ────────────────────────────────────────

    public function testAdminOffIs503AndNothingGoesOut(): void {
        $this->deckWithEveryKind();
        $this->policy = $this->realPolicy();
        $this->userValues['alice/ai_notice'] = '1';
        $this->appValues['ai_enabled'] = '0';
        $this->startFails(503, 'AI is not available.');
    }

    public function testWithoutTheNoticeIs409WithNoticeFlag(): void {
        $this->deckWithEveryKind();
        $this->policy = $this->realPolicy();
        try {
            $this->service()->start($this->room, 'alice');
            $this->fail('expected 409');
        } catch (AiDraftException $e) {
            $this->assertSame(409, $e->status());
            $this->assertSame(['notice' => true], $e->data());
        }
        $this->assertSame([], $this->scheduled);
    }

    public function testDailyLimitCountsSummariesWithATaskOnly(): void {
        $this->policy = $this->realPolicy();
        $this->userValues['alice/ai_notice'] = '1';
        $this->appValues['ai_daily_limit'] = '1';
        // Nothing for the AI (only a choice question): no task, not counted.
        $this->addPoll('choice', 'Pick one', [['id' => 'AA', 'label' => 'Paris']]);
        $this->assertSame('done', $this->service()->start($this->room, 'alice')['status']);
        $this->assertArrayNotHasKey('alice/ai_day', $this->userValues);

        $this->deckWithEveryKind();
        $this->assertSame(['task' => 71], $this->service()->start($this->room, 'alice'));
        $this->assertSame('2026-11-05:1', $this->userValues['alice/ai_day']);
        $this->startFails(429, 'You have used all 1 AI request for today.', 1);
    }

    public function testOnlyThisAccountsSummaryOfThisRoomCanBeRead(): void {
        $this->stored[1] = $this->task(1, Task::STATUS_SUCCESSFUL, '{}', 'pulse', 'pulse-summary:OTHER1');
        $this->stored[2] = $this->task(2, Task::STATUS_SUCCESSFUL, '{}', 'pulse', 'pulse-draft:ABCDEF');
        $this->stored[3] = $this->task(3, Task::STATUS_SUCCESSFUL, '{}', 'other', 'pulse-summary:ABCDEF');
        foreach ([1, 2, 3, 99] as $id) {
            foreach (['status', 'cancel'] as $what) {
                try {
                    $this->service()->$what($this->room, 'alice', $id);
                    $this->fail("$what $id");
                } catch (AiDraftException $e) {
                    $this->assertSame(404, $e->status());
                }
            }
        }
        try {
            $this->stored[4] = $this->task(4, Task::STATUS_RUNNING);
            $this->service()->status($this->room, 'bob', 4);
            $this->fail('bob read alice\'s task');
        } catch (AiDraftException $e) {
            $this->assertSame(404, $e->status());
        }
    }

    public function testRunningFailedAndUnusable(): void {
        $this->deckWithEveryKind();
        $this->stored[1] = $this->task(1, Task::STATUS_RUNNING);
        $this->stored[2] = $this->task(2, Task::STATUS_FAILED);
        $this->stored[3] = $this->task(3, Task::STATUS_SUCCESSFUL, 'I cannot help with that.');
        $this->stored[4] = $this->task(4, Task::STATUS_SUCCESSFUL, '{"questions":[{"n":1,"points":["choice questions are not sent"]}]}');

        $this->assertSame(['status' => 'running'], $this->service()->status($this->room, 'alice', 1));
        $this->assertSame('failed', $this->service()->status($this->room, 'alice', 2)['status']);
        $this->assertSame(['status' => 'failed', 'message' => 'The AI\'s answer could not be used. Try again.'], $this->service()->status($this->room, 'alice', 3));
        $this->assertSame('failed', $this->service()->status($this->room, 'alice', 4)['status']);
    }

    // ── Routes ─────────────────────────────────────────────────────────────

    public function testStartAnswers201WithATaskAnd200WithAText(): void {
        $this->deckWithEveryKind();
        $this->assertSame(Http::STATUS_CREATED, $this->controller()->start('ABCDEF')->getStatus());

        $this->polls = [];
        $this->addPoll('choice', 'Pick one', [['id' => 'AA', 'label' => 'Paris']]);
        $response = $this->controller()->start('ABCDEF');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('done', $response->getData()['status']);
    }

    public function testRouteErrors(): void {
        $this->deckWithEveryKind();
        $this->aiHelp = false;
        $this->assertSame(Http::STATUS_CONFLICT, $this->controller()->start('ABCDEF')->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->start('ZZZZZZ')->getStatus(), 'someone else\'s room');
        $this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $this->controller(limited: true)->start('ABCDEF')->getStatus());
        $this->assertSame([], $this->scheduled);
    }

    public function testSaveWritesTheTextAsSentAndRefusesEmptyOrLongText(): void {
        $files = $this->createMock(FilesExportService::class);
        $saved = [];
        $files->method('saveSummary')->willReturnCallback(function (Room $r, string $uid, string $text) use (&$saved): array {
            $saved[] = [$uid, $text];
            return ['fileId' => 9, 'name' => 'x.md', 'path' => '/Pulse/x.md'];
        });

        $ok = $this->controller(['text' => "# Edited\n\n- by hand"], $files)->save('ABCDEF');
        $this->assertSame(Http::STATUS_CREATED, $ok->getStatus());
        $this->assertSame(9, $ok->getData()['fileId']);
        $this->assertSame([['alice', "# Edited\n\n- by hand"]], $saved);

        foreach ([null, '', "  \n", ['x'], str_repeat('a', AiSummaryController::TEXT_MAX + 1)] as $bad) {
            $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(['text' => $bad], $files)->save('ABCDEF')->getStatus());
        }
        $this->assertCount(1, $saved);
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(['text' => 'x'], $files)->save('ZZZZZZ')->getStatus());
    }

    public function testSaveAnswersAFilesProblemWith409(): void {
        $files = $this->createMock(FilesExportService::class);
        $files->method('saveSummary')->willThrowException(new FilesExportException('You cannot save files in your Files.'));

        $response = $this->controller(['text' => 'x'], $files)->save('ABCDEF');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame(['message' => 'You cannot save files in your Files.'], $response->getData());
    }

    public function testRoutesAreLoginOnlyWithCsrf(): void {
        foreach (['start', 'status', 'cancel', 'save'] as $method) {
            $m = new \ReflectionMethod(AiSummaryController::class, $method);
            $this->assertCount(1, $m->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class), $method);
            $this->assertCount(0, $m->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class), $method);
            $this->assertCount(0, $m->getAttributes(\OCP\AppFramework\Http\Attribute\PublicPage::class), $method);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * 1 choice "Pick one" (Paris 3, Rome 1), 2 free text with a hidden answer,
     * 3 word cloud with a hidden word; nickname "Nicky" on the leaderboard.
     */
    private function deckWithEveryKind(): void {
        $this->leaderboard = [['rank' => 1, 'nickname' => 'Nicky', 'score' => 3]];
        $choice = $this->addPoll('choice', 'Pick one', [['id' => 'AA', 'label' => 'Paris'], ['id' => 'BB', 'label' => 'Rome']]);
        $this->votes[$choice->getId()] = [$this->vote(1, 'AA'), $this->vote(2, 'AA'), $this->vote(3, 'AA'), $this->vote(4, 'BB')];
        $text = $this->addPoll('text', 'What did you like?');
        $text->setModeration(['hidden' => ['rude thing']]);
        $this->votes[$text->getId()] = [$this->vote(1, 'The pizza'), $this->vote(2, 'the  pizza'), $this->vote(3, 'Music'), $this->vote(4, 'Rude thing')];
        $words = $this->addPoll('words', 'One word');
        $words->setModeration(['hidden' => ['secretword']]);
        $this->votes[$words->getId()] = [$this->vote(1, ['apple', 'SecretWord']), $this->vote(2, ['Apple']), $this->vote(3, ['pear'])];
    }

    private function addPoll(string $type, string $question, array $options = []): Poll {
        $poll = new Poll();
        $poll->setId(count($this->polls) + 1);
        $poll->setRoomId(5);
        $poll->setType($type);
        $poll->setQuestion($question);
        $poll->setStatus('ended');
        $poll->setOptions((string)json_encode($options));
        $this->polls[$poll->getId()] = $poll;
        return $poll;
    }

    private function vote(int $n, mixed $value): Vote {
        $vote = new Vote();
        $vote->setId($n);
        $vote->setVoterToken('tok-' . $n);
        $vote->setPayload((string)json_encode(['value' => $value]));
        return $vote;
    }

    private function task(int $id, int $status, ?string $output = null, string $app = 'pulse', string $custom = 'pulse-summary:ABCDEF'): Task {
        $t = new Task('core:text2text', ['input' => 'x'], $app, 'alice', $custom);
        $t->setId($id);
        $t->setStatus($status);
        if ($output !== null) {
            $t->setOutput(['output' => $output]);
        }
        return $t;
    }

    private function startFails(int $status, string $message, int $scheduledBefore = 0): void {
        try {
            $this->service()->start($this->room, 'alice');
            $this->fail('expected ' . $status);
        } catch (AiDraftException $e) {
            $this->assertSame($status, $e->status(), $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertCount($scheduledBefore, $this->scheduled, 'nothing (more) was sent to the AI');
    }

    private function policy(): \OCA\Pulse\Service\AiPolicy {
        return $this->policy ??= $this->openPolicy();
    }

    private function moderation(): ModerationService {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(fn (string $u, string $app, string $key, mixed $d = ''): mixed =>
            $key === ModerationService::PREF . '5' && $this->aiHelp ? '1' : $d);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);
        return new ModerationService($this->createMock(PollMapper::class), $this->createMock(PaceService::class), $config, $time,
            $this->tasks, $this->l10n, $this->createMock(LoggerInterface::class), $this->policy());
    }

    private function state(ModerationService $moderation): StateService {
        $voteMapper = $this->createMock(VoteMapper::class);
        $voteMapper->method('findByPoll')->willReturnCallback(fn (int $id): array => $this->votes[$id] ?? []);
        $deck = $this->createMock(DeckService::class);
        $deck->method('deck')->willReturnCallback(fn (): array => array_values($this->polls));

        $state = (new \ReflectionClass(StateService::class))->newInstanceWithoutConstructor();
        foreach ([
            'voteMapper' => $voteMapper,
            'tallyService' => new TallyService(),
            'deckService' => $deck,
            'timeFactory' => $this->createMock(ITimeFactory::class),
            'l10n' => $this->l10n,
            'moderation' => $moderation,
        ] as $name => $value) {
            (new ReflectionProperty(StateService::class, $name))->setValue($state, $value);
        }
        return $state;
    }

    private function service(): AiSummaryService {
        $moderation = $this->moderation();
        $votes = $this->createMock(VoteService::class);
        $votes->method('leaderboardFor')->willReturnCallback(function () {
            $this->leaderboardCalls++;
            return $this->leaderboard;
        });
        $files = $this->createMock(FilesExportService::class);
        $files->method('today')->willReturn('2026-11-05');
        return new AiSummaryService($this->tasks, $this->state($moderation), $moderation, $votes, $files,
            $this->l10n, $this->createMock(LoggerInterface::class), $this->policy());
    }

    private function controller(array $params = [], ?FilesExportService $files = null, bool $limited = false): AiSummaryController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $k) => $params[$k] ?? null);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('getOwnedRoom')->willReturnCallback(fn (string $code): Room => $code === 'ABCDEF' ? $this->room : throw new NotOwnerException());
        $limiter = $this->createMock(ILimiter::class);
        if ($limited) {
            $limiter->method('registerUserRequest')->willThrowException(new class('limit') extends \Exception implements IRateLimitExceededException {
            });
        }
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(fn ($app, $key, $default) => $default);
        return new AiSummaryController($request, $session, $rooms, $this->service(), $files ?? $this->createMock(FilesExportService::class),
            $this->l10n, $limiter, new Limits($appConfig));
    }
}
