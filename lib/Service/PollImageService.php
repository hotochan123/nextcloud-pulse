<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\Security\ISecureRandom;

/**
 * Images for questions. They live in the app's AppData area (not in the web root,
 * not in anyone's files) and are served through dedicated endpoints.
 *
 * Uploaded images are ALWAYS re-encoded instead of passed through: that drops
 * EXIF data, embedded payloads and polyglot files and, as a side effect, caps
 * the edge length. What comes out here is a PNG or JPEG freshly written by
 * GD — nothing else.
 */
class PollImageService {
    /** Upload limit (bytes). PHP itself allows much more here. */
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** Longest edge after downscaling — enough for any projector. */
    private const MAX_EDGE = 1600;
    /** Guard against "decompression bombs": pixels before downscaling. */
    private const MAX_PIXELS = 50_000_000;
    private const FOLDER = 'polls';

    private const ACCEPTED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'png',   // stored as PNG (animation is lost)
        IMAGETYPE_WEBP => 'png',
    ];

    public function __construct(
        private PollMapper $pollMapper,
        private IAppDataFactory $appDataFactory,
        private ISecureRandom $secureRandom,
        private IL10N $l10n,
    ) {
    }

    /**
     * Validate an uploaded image, re-encode it, store it and record it on the
     * question. A previous image of the same question is replaced.
     *
     * @param array{tmp_name?:string, size?:int, error?:int} $upload $_FILES entry
     * @return Poll the updated question
     * @throws \InvalidArgumentException for a broken/too large/missing image
     */
    public function store(Poll $poll, array $upload): Poll {
        // `image[]=…` (or image[a]) yields a list per field instead of a
        // value — that is not an image (without this check, (int) on it would be 1 =
        // UPLOAD_ERR_INI_SIZE, i.e. "too large").
        if (!is_int($upload['error'] ?? UPLOAD_ERR_NO_FILE) || !is_string($upload['tmp_name'] ?? '')) {
            throw new \InvalidArgumentException($this->l10n->t('No image received.'));
        }
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \InvalidArgumentException($this->l10n->t('The image is too large.'));
        }
        if ($error !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
            throw new \InvalidArgumentException($this->l10n->t('No image received.'));
        }
        $path = (string)$upload['tmp_name'];
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('Please upload an image of up to 5 MB.'));
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new \InvalidArgumentException($this->l10n->t('No image received.'));
        }
        // Do not trust the client's Content-Type — ask the file itself.
        $info = @getimagesizefromstring($raw);
        if ($info === false || !isset(self::ACCEPTED[$info[2]])) {
            throw new \InvalidArgumentException($this->l10n->t('Only PNG, JPEG, GIF or WebP.'));
        }
        if ((int)$info[0] * (int)$info[1] > self::MAX_PIXELS) {
            throw new \InvalidArgumentException($this->l10n->t('The image has too many pixels.'));
        }

        [$data, $ext] = $this->reencode($raw, (int)$info[2]);

        // Write first, then remove the old file: if writing fails,
        // the previous image is kept.
        $old = $this->fileName($poll);
        $name = $this->secureRandom->generate(16, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS) . '.' . $ext;
        $this->folder()->newFile($this->storedName($poll->getId(), $name), $data);
        $poll->setImage($name);
        $poll = $this->pollMapper->update($poll);
        if ($old !== '' && $old !== $name) {
            $this->deleteFile($poll->getId(), $old);
        }
        return $poll;
    }

    /** Remove the image (file + record on the question). A no-op without an image. */
    public function remove(Poll $poll): Poll {
        $name = $this->fileName($poll);
        if ($name === '') {
            return $poll;
        }
        $this->deleteFile($poll->getId(), $name);
        $poll->setImage('');
        return $this->pollMapper->update($poll);
    }

    /**
     * Read a question's image (for serving).
     *
     * @return ?array{content:string, mime:string, name:string}
     */
    public function read(Poll $poll): ?array {
        $name = $this->fileName($poll);
        if ($name === '') {
            return null;
        }
        try {
            $file = $this->folder()->getFile($this->storedName($poll->getId(), $name));
            return [
                'content' => $file->getContent(),
                'mime' => str_ends_with($name, '.jpg') ? 'image/jpeg' : 'image/png',
                'name' => $name,
            ];
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Copy the image along when duplicating — the copy gets its own file,
     * so deleting one room does not take the other room's image with it.
     */
    public function copy(Poll $src, Poll $dst): void {
        $data = $this->read($src);
        if ($data === null) {
            return;
        }
        $ext = str_ends_with($data['name'], '.jpg') ? 'jpg' : 'png';
        $name = $this->secureRandom->generate(16, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS) . '.' . $ext;
        $this->folder()->newFile($this->storedName($dst->getId(), $name), $data['content']);
        $dst->setImage($name);
        $this->pollMapper->update($dst);
    }

    /**
     * Clean up when a question (or a whole room) goes away. The question is
     * NOT written here any more — it is about to be deleted anyway.
     */
    public function discard(Poll $poll): void {
        $name = $this->fileName($poll);
        if ($name !== '') {
            $this->deleteFile($poll->getId(), $name);
        }
    }

    // ── internal ────────────────────────────────────────────────────────────

    /**
     * Re-encode + downscale to MAX_EDGE. JPEG stays JPEG (photos), everything
     * else becomes PNG (transparency is preserved).
     *
     * @return array{0:string, 1:string} raw data + file extension
     */
    private function reencode(string $raw, int $type): array {
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            throw new \InvalidArgumentException($this->l10n->t('The image could not be read.'));
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = max($w, $h) > self::MAX_EDGE ? self::MAX_EDGE / max($w, $h) : 1.0;
        if ($scale < 1.0) {
            $resized = imagescale($img, (int)round($w * $scale), (int)round($h * $scale));
            if ($resized !== false) {
                imagedestroy($img);
                $img = $resized;
            }
        }

        $jpeg = $type === IMAGETYPE_JPEG;
        if (!$jpeg) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        ob_start();
        $ok = $jpeg ? imagejpeg($img, null, 85) : imagepng($img, null, 6);
        $data = (string)ob_get_clean();
        imagedestroy($img);
        if (!$ok || $data === '') {
            throw new \InvalidArgumentException($this->l10n->t('The image could not be processed.'));
        }
        return [$data, $jpeg ? 'jpg' : 'png'];
    }

    private function fileName(Poll $poll): string {
        $name = (string)($poll->getImage() ?? '');
        // Accept only the expected pattern — the value ends up in a file name.
        return preg_match('/^[a-z0-9]{16}\.(png|jpg)$/', $name) === 1 ? $name : '';
    }

    private function storedName(int $pollId, string $name): string {
        return $pollId . '-' . $name;
    }

    private function deleteFile(int $pollId, string $name): void {
        try {
            $this->folder()->getFile($this->storedName($pollId, $name))->delete();
        } catch (NotFoundException) {
            // already gone — nothing to do
        }
    }

    private function folder(): ISimpleFolder {
        $appData = $this->appDataFactory->get('pulse');
        try {
            return $appData->getFolder(self::FOLDER);
        } catch (NotFoundException) {
            return $appData->newFolder(self::FOLDER);
        }
    }
}
