<?php
/**
 * Katalog der Einheit D, Teil Anhänge (PR #2b, Welle 2): `Service\AttachmentService` und `Repository\AttachmentRepository` laut Vertrag
 * DESIGN-2b §4.6, §7.4 und Entwurf medien §6.4.
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env, requires]
 *
 * Herkunft: medien §6.4 (`upload-*`, `claim-*`, `purge-*`, `resolve-*`), Vertrag §7.4 (Nacharbeit: `upload-quota-unlocked`; Fassung 3:
 * `gd-no-semaphore`, `gd-no-budget`, `purge-tmp-skipped`, `upload-bidi-kept`; Fassung 3.1: `gd-format-flat`, `gd-budget-system`,
 * `jpeg-scans-unbounded`). Die übrigen Zeilen sichern Regeln dieser Einheit ohne Vorlage.
 *
 * Abweichung vom Entwurf: `claim-sql-pending` zielt auf `community-uploads` statt `community-repositories` (Abschnitt L prüft `claim()`
 * auf eine zugeordnete Zeile direkt; `community-repositories` gehört in W2 B1/B2). Nicht hier: `edit-remove-cache` und `add-only-no-clear`
 * (T in W3, `catalog-2b-services.php`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$service = 'core/Services/Community/Service/AttachmentService.php';
$repo = 'core/Services/Community/Repository/AttachmentRepository.php';
$gd = ['gd'];

return [
    // ── Namen und Typen (U1)
    'upload-inner-extension' => [$service, 'foreach (array_slice($parts, 1, -1) as $inner) {', 'foreach ([] as $inner) {', 'community-uploads', false, 'gefährliche Innenendung (shell.php.jpg) wird abgelehnt', true, []],
    'upload-type-setting-ignored' => [$service, 'if (empty($enabled[$extension])) {', 'if (false) {', 'community-uploads', false, 'attachment_types: abgeschaltete Typen werden abgelehnt', false, []],
    'upload-bidi-kept' => [$service, "preg_replace('/[\\p{Cf}\\x{2028}\\x{2029}]/u', '', \$name)", '$name', 'community-uploads', false, 'Dateiname ohne Bidi- und Formatzeichen (U+202E, U+200B) und U+2028', false, []],
    'upload-name-uncleaned' => [$service, '$name = FormUploadService::cleanFilename($raw);', '$name = $raw;', 'community-uploads', false, 'Dateiname ohne Pfad und Steuerzeichen (FormUploadService::cleanFilename)', false, []],

    // ── Inhalt (U2, U3, U5)
    'upload-finfo-empty-allowed' => [$service, "if (\$mime === '' || in_array(\$mime, self::DANGEROUS_MIME, true) || !in_array(\$mime, self::TYPES[\$extension]['mime'], true)) {", "if (\$mime !== '' && (in_array(\$mime, self::DANGEROUS_MIME, true) || !in_array(\$mime, self::TYPES[\$extension]['mime'], true))) {", 'community-uploads', false, 'unbekannter Inhaltstyp (finfo leer) wird abgelehnt (fail-closed, anders als MediaService)', true, []],
    'upload-head-scan' => [$service, '/<\\?php|<script|<html|<svg|<!doctype/i', '/^\\x00\\x00$/', 'community-uploads', false, 'Nicht-Bilder mit PHP, Script, HTML oder SVG im Kopf werden abgelehnt', false, []],
    'upload-max-kb-ignored' => [$service, "self::effectiveMaxBytes(\$this->settings->int('attachment_max_kb'))", 'self::effectiveMaxBytes(51200)', 'community-uploads', false, 'attachment_max_kb begrenzt die Dateigröße', false, []],

    // ── Bilder (U6, U7; Vertrag §4.6 Nr. 3)
    'upload-pixel-limit-late' => [$service, 'if ($width * $height > self::MAX_PIXELS) {', 'if (false) {', 'community-uploads', false, 'Pixelgrenze vor jedem Dekodieren (Pixelbombe ⇒ attachment_dimensions)', false, [], $gd],
    'jpeg-scans-unbounded' => [$service, "if (\$scan['scans'] > self::MAX_JPEG_SCANS) {", 'if (false) {', 'community-uploads', false, 'JPEG mit mehr als 32 Scans ⇒ attachment_image vor Slot und Speicherschätzung', false, [], $gd],
    'gd-no-budget' => [$service, "if (\$free < self::decodeNeed(\$width, \$height, \$extension, \$scan['coefficients'], \$channels)) {", 'if (false) {', 'community-uploads', false, 'Speicherschätzung je Format gegen das Budget vor dem Dekodieren', false, [], $gd],
    'gd-no-semaphore' => [$service, '$release = ($this->slot)();', '$release = static function (): void {};', 'community-uploads', false, 'Dekodieren nur mit Slot (beide belegt ⇒ rate_limited)', false, [], $gd],
    'gd-slot-not-released' => [$service, '            $release();', '            null;', 'community-uploads', false, 'Slot wird im finally freigegeben, auch nach einem Dekodierfehler', false, [], $gd],
    'gd-format-flat' => [$service, '$perPixel = 4 + self::DECODE_EXTRA[$extension] + ($coefficients ? 2 * max(1, $channels) : 0);', '$perPixel = 4;', 'community-uploads', false, 'decodeNeed() mit Decoderpuffern je Format und Koeffizienten progressiver JPEG', false, []],
    'gd-budget-system' => [$service, "return str_contains(\$gdVersion, 'bundled') && \$memoryLimit > 0 ? min(self::DECODE_BUDGET, \$memoryLimit - \$usage) : self::DECODE_BUDGET;", "return str_contains(\$gdVersion, 'bundled') && \$memoryLimit > 0 ? \$memoryLimit - \$usage : PHP_INT_MAX;", 'community-uploads', false, 'budget(): System-libgd und gebündelte GD mit höchstens DECODE_BUDGET', false, []],
    'upload-alpha-lost' => [$service, 'imagesavealpha($image, true);', 'imagesavealpha($image, false);', 'community-uploads', false, 'PNG-Alpha bleibt erhalten', false, [], $gd],
    'upload-orientation-6' => [$service, "6 => [['rotate', 270]],", "6 => [['rotate', 90]],", 'community-uploads', false, 'EXIF-Orientierung 6 dreht um 90° im Uhrzeigersinn', false, [], $gd],
    'upload-orientation-5-flip' => [$service, "5 => [['rotate', 270], ['flip', 'h']],", "5 => [['rotate', 270]],", 'community-uploads', false, 'EXIF-Orientierung 5 dreht und spiegelt', false, [], $gd],
    'upload-no-reencode' => [$service, 'if (!self::encode($image, $extension, $tmpTarget)) {', 'if (!copy($tmp, $tmpTarget)) {', 'community-uploads', false, 'Bilder werden neu kodiert (EXIF, GPS und PHP im COM entfernt)', false, [], $gd],
    'upload-edge-limit' => [$service, 'if (max($width, $height) > self::MAX_EDGE) {', 'if (false) {', 'community-uploads', false, 'längste Kante höchstens 2560 px', false, [], $gd],
    'upload-preview-missing' => [$service, 'if (max($finalWidth, $finalHeight) > self::PREVIEW_EDGE) {', 'if (false) {', 'community-uploads', false, 'Vorschau bei langer Kante über 480 px', false, [], $gd],
    'upload-animated-flag' => [$service, "\$animated = \$frames !== null && \$frames[\"\\x21\\xF9\\x04\"] > 1;", '$animated = false;', 'community-uploads', false, 'animiertes GIF wird erkannt (nur das erste Bild bleibt)', false, [], $gd],

    // ── Rechte, Grenzen und Ablage (U9, U10)
    'upload-throttle-skipped' => [$service, "\$gate = (\$this->throttle)('upload', \$userId);", "\$gate = ['allowed' => true, 'retry_after' => 0];", 'community-uploads', false, 'Drossel upload je Mitglied', false, []],
    'upload-edit-right-skipped' => [$service, "\$edit = (\$this->can)(\$userId, 'edit', ['post_id' => \$postId, 'now' => \$nowTs]);", '$edit = AccessDecision::allow();', 'community-uploads', false, 'Upload an einen Beitrag verlangt das Bearbeitungsrecht', false, []],
    'upload-day-quota' => [$service, ">= \$limits['attachments_per_day']", "> \$limits['attachments_per_day']", 'community-uploads', false, 'Tageskontingent: erreicht heißt abgelehnt', false, []],
    'upload-quota-not-rechecked' => [$service, "return ['error' => \$quota];", 'null;', 'community-uploads', false, 'Kontingente werden unter der Statistiksperre erneut gezählt', false, []],
    'upload-quota-unlocked' => [$service, '$this->stats->lockForUpdate($userId, $now);', '', 'community-db', true, 'Kontingent exakt unter der Statistiksperre des Uploaders (6 gleichzeitige am Kontingent 3: genau 3 Zeilen)', false, ['CMS_TEST_DB_SECTIONS' => 'race-upload-quota']],
    'upload-month-local' => [$service, "(\$this->directory)(gmdate('Y/m', \$nowTs))", "(\$this->directory)(date('Y/m', \$nowTs))", 'community-uploads', false, 'Monatsordner nach UTC', false, []],
    'upload-temps-kept' => [$service, '            if (!$committed) {', '            if (false) {', 'community-uploads', false, 'Ablehnung oder Fehler vor dem Commit löscht die Zwischendateien', false, []],

    // ── Zuordnung (Vertrag §3.10 Ebene 5)
    'claim-owner-unchecked' => [$service, "if (\$row === null || \$row['post_id'] !== null || (int) \$row['user_id'] !== \$userId) {", 'if ($row === null) {', 'community-uploads', false, 'unter der Sperre nur eigene, noch nicht zugeordnete Anhänge', true, []],
    'claim-prepare-foreign' => [$service, "if (\$row === null || (int) \$row['user_id'] !== \$userId) {", 'if ($row === null) {', 'community-uploads', false, 'aufgeführte fremde Uploads ⇒ attachment_not_found', false, []],
    'claim-verify-remove-foreign' => [$service, "if (\$row === null || \$row['post_id'] !== \$postId || \$postId === null) {", 'if ($row === null) {', 'community-uploads', false, 'zu entfernende Anhänge gehören unter der Sperre noch zum Beitrag', false, []],
    'claim-count-unchecked' => [$service, 'if ($claimed !== count($ids)) {', 'if (false) {', 'community-uploads', false, 'abweichende Zahl zugeordneter Zeilen wirft (Rücknahme)', false, []],
    'claim-flag-pending' => [$service, "if (\$postPublished && \$plan['ids'] !== []", "if (\$plan['ids'] !== []", 'community-uploads', false, 'has_attachments nur für veröffentlichte Beiträge', false, []],
    'claim-sql-pending' => [$repo, "AND post_id IS NULL AND user_id = ?'", "AND user_id = ?'", 'community-uploads', false, 'claim() ordnet nur verwaiste Zeilen zu (zweite Linie hinter verify)', false, []],
    'claim-lock-missing' => [$repo, "' ORDER BY id ASC' . self::forUpdate()", "' ORDER BY id ASC'", 'community-uploads', false, 'Anhänge werden über PRIMARY gesperrt (FOR UPDATE)', false, []],

    // ── Auflösung (U11, E2)
    'resolve-placeholder-served' => [$service, "if (!\$decision->allowed || \$decision->view !== 'full') {", 'if (!$decision->allowed) {', 'community-uploads', false, 'Anhänge von Platzhaltern (ausgeblendet für Dritte) ⇒ not_found', true, []],
    'resolve-orphan-any-owner' => [$service, "\$viewerId === null || \$viewerId !== (int) \$row['user_id']", '$viewerId === null', 'community-uploads', false, 'verwaiste Uploads sieht nur der Eigentümer', false, []],
    'resolve-inline-all' => [$service, "'inline' => self::TYPES[\$extension]['image'] !== null,", "'inline' => true,", 'community-uploads', false, 'inline nur für Bilder', false, []],
    'resolve-path-pattern' => [$service, "public const STORAGE_PATTERN = '#^\\d{4}/\\d{2}/[a-f0-9]{32}\\.[a-z0-9]{1,10}\$#';", "public const STORAGE_PATTERN = '#^.+\$#';", 'community-uploads', false, 'manipulierter storage_path ⇒ not_found', false, []],
    'resolve-public-always' => [$service, "\$public = \$guest->allowed && \$guest->view === 'full';", '$public = true;', 'community-uploads', false, 'public nur, wenn Gäste den Beitrag voll sehen (steuert no-store)', false, []],

    // ── Aufräumen (U12, Vertrag §4.6 Nr. 7)
    'purge-file-first' => [$service, "if (\$this->repo->deletePending(\$row['id']) !== 1) {", 'if (false) {', 'community-uploads', false, 'Aufräumen löscht zuerst die Zeile (bedingt verwaist), dann die Datei', false, []],
    'purge-local-time' => [$service, "\$cutoff = gmdate('Y-m-d H:i:s', \$nowTs - self::ORPHAN_SECONDS);", "\$cutoff = date('Y-m-d H:i:s', \$nowTs - self::ORPHAN_SECONDS);", 'community-uploads', false, 'Grenze der Aufräumliste in UTC', false, []],
    'purge-tmp-skipped' => [$service, 'foreach ($this->staleTemporaries($nowTs, $limit) as $file) {', 'foreach ([] as $file) {', 'community-uploads', false, 'Zwischendateien älter als 24 h werden ohne Datenbank gelöscht', false, []],
];
