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
 * Bilder zu Fragen. Liegen im AppData-Bereich der App (nicht im Web-Root, nicht
 * in den Dateien einer Person) und werden über eigene Endpunkte ausgeliefert.
 *
 * Hochgeladene Bilder werden IMMER neu kodiert statt durchgereicht: das wirft
 * EXIF-Daten, eingebettete Nutzlasten und Polyglot-Dateien weg und deckelt
 * nebenbei die Kantenlänge. Was hier herauskommt, ist ein frisch von GD
 * geschriebenes PNG oder JPEG — sonst nichts.
 */
class PollImageService {
    /** Obergrenze für den Upload (Bytes). PHP selbst erlaubt hier viel mehr. */
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** Längste Kante nach dem Verkleinern — reicht für jeden Beamer. */
    private const MAX_EDGE = 1600;
    /** Grenze gegen „Dekompressionsbomben": Pixel vor dem Verkleinern. */
    private const MAX_PIXELS = 50_000_000;
    private const FOLDER = 'polls';

    private const ACCEPTED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'png',   // wird als PNG gespeichert (Animation geht verloren)
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
     * Hochgeladenes Bild prüfen, neu kodieren, ablegen und an der Frage
     * vermerken. Ein vorheriges Bild derselben Frage wird ersetzt.
     *
     * @param array{tmp_name?:string, size?:int, error?:int} $upload $_FILES-Eintrag
     * @return Poll die aktualisierte Frage
     * @throws \InvalidArgumentException bei fehlerhaftem/zu großem/keinem Bild
     */
    public function store(Poll $poll, array $upload): Poll {
        // `image[]=…` (oder image[a]) liefert je Feld eine Liste statt eines
        // Werts — das ist kein Bild (ohne die Probe hieße (int) darauf 1 =
        // UPLOAD_ERR_INI_SIZE, also „zu groß“).
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
        // Nicht dem Content-Type des Clients trauen — die Datei selbst befragen.
        $info = @getimagesizefromstring($raw);
        if ($info === false || !isset(self::ACCEPTED[$info[2]])) {
            throw new \InvalidArgumentException($this->l10n->t('Only PNG, JPEG, GIF or WebP.'));
        }
        if ((int)$info[0] * (int)$info[1] > self::MAX_PIXELS) {
            throw new \InvalidArgumentException($this->l10n->t('The image has too many pixels.'));
        }

        [$data, $ext] = $this->reencode($raw, (int)$info[2]);

        // Erst schreiben, dann die alte Datei entfernen: geht das Schreiben schief,
        // bleibt das bisherige Bild erhalten.
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

    /** Bild entfernen (Datei + Vermerk an der Frage). Ohne Bild ein No-op. */
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
     * Bild einer Frage lesen (zum Ausliefern).
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
     * Bild beim Duplizieren mitkopieren — die Kopie bekommt eine eigene Datei,
     * damit das Löschen des einen Raums das Bild des anderen nicht mitnimmt.
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
     * Aufräumen, wenn eine Frage (oder ein ganzer Raum) verschwindet. Die Frage
     * wird hier NICHT mehr geschrieben — sie ist ohnehin gleich gelöscht.
     */
    public function discard(Poll $poll): void {
        $name = $this->fileName($poll);
        if ($name !== '') {
            $this->deleteFile($poll->getId(), $name);
        }
    }

    // ── intern ──────────────────────────────────────────────────────────────

    /**
     * Neu kodieren + auf MAX_EDGE verkleinern. JPEG bleibt JPEG (Fotos), alles
     * andere wird PNG (Transparenz bleibt erhalten).
     *
     * @return array{0:string, 1:string} Rohdaten + Dateiendung
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
        // Nur das erwartete Muster akzeptieren — der Wert fließt in einen Dateinamen.
        return preg_match('/^[a-z0-9]{16}\.(png|jpg)$/', $name) === 1 ? $name : '';
    }

    private function storedName(int $pollId, string $name): string {
        return $pollId . '-' . $name;
    }

    private function deleteFile(int $pollId, string $name): void {
        try {
            $this->folder()->getFile($this->storedName($pollId, $name))->delete();
        } catch (NotFoundException) {
            // schon weg — nichts zu tun
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
