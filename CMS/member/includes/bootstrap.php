<?php
declare(strict_types=1);

/**
 * Gemeinsamer Einstieg der Member-Vorlagen. Wird mit `require` (nicht `require_once`) eingebunden, damit
 * `$controller`, `$settings`, `$t` und `$e` auch dann im Scope der Vorlage stehen, wenn eine Fehler- oder
 * Plugin-Seite nach einer anderen Vorlage im selben Request gerendert wird. Mehrfaches Ausführen ist unkritisch.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-member-controller.php';

$controller = \CMS\MemberArea\MemberController::instance();
$controller->requireAuth();
\CMS\CacheManager::instance()->sendResponseHeaders('private');
$settings = $controller->getSettings();

/**
 * Template-Helfer des Mitgliederbereichs:
 * - `$t('member.schluessel', ['name' => …])` liefert den Oberflächentext in der Sprache der Anfrage (MD-27),
 * - `$e($wert)` escaped für HTML-Text und -Attribute.
 */
$t = static fn (string $key, array $params = []): string => \CMS\Member\MemberI18n::t($key, $params);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
