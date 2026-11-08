<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Listener;

use OCA\Pulse\Service\GradingSuggestionService;
use OCA\Pulse\Service\ModerationService;
use OCA\Pulse\Service\ThemeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use Psr\Log\LoggerInterface;

/**
 * Result of an AI check of answers (ModerationService::afterVote): flagged
 * answers are held back from the projector, the others pass. Also the
 * result of "Group with AI" (ThemeService) and of the AI grading suggestion
 * (GradingSuggestionService). Runs in the
 * process that finished the task (usually the task processing worker), for
 * every app's task: ModerationService ignores tasks that are not Pulse's.
 * Nothing may escape from here, it would break the worker's run.
 *
 * @template-implements IEventListener<TaskSuccessfulEvent|TaskFailedEvent>
 */
class ModerationTaskListener implements IEventListener {
    public function __construct(
        private ModerationService $moderation,
        private ThemeService $themes,
        private GradingSuggestionService $grading,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof TaskSuccessfulEvent) && !($event instanceof TaskFailedEvent)) {
            return;
        }
        try {
            $this->moderation->onTaskResult($event->getTask(), $event instanceof TaskSuccessfulEvent);
        } catch (\Throwable $e) {
            $this->logger->warning('Pulse: could not apply the AI check', ['exception' => $e]);
        }
        try {
            $this->themes->onTaskResult($event->getTask(), $event instanceof TaskSuccessfulEvent);
        } catch (\Throwable $e) {
            $this->logger->warning('Pulse: could not apply the AI grouping', ['exception' => $e]);
        }
        try {
            $this->grading->onTaskResult($event->getTask(), $event instanceof TaskSuccessfulEvent);
        } catch (\Throwable $e) {
            $this->logger->warning('Pulse: could not apply the AI grading suggestion', ['exception' => $e]);
        }
    }
}
