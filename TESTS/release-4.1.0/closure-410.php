<?php
/**
 * Release-Prüfung 4.1.0, Audit-Abschluss (Prüfbereich 6): FUN-410-07 und FUN-410-28 sowie die Konsistenz der
 * Schema-/Rechte-Marker (v26, capabilities_v26).
 * Wird von run.php eingebunden ($src, $root, ABSPATH, Autoloader).
 */

declare(strict_types=1);

T::run('FUN-410-07: „Vorlagen“ nicht in Wissensdatenbank- und Hub-Editoren einfügbar', static function () use ($src): void {
    $js = $src('assets/js/editor-init.js');
    T::check(str_contains($js, "var REUSABLE_BLOCK_EXCLUDED_HOLDERS = '.editorjs-wrap--cms-knowledgebase, .hub-editorjs-holder';"), 'ausgeschlossen: Wissensdatenbank-Editor und Hub-Holder');
    T::check(str_contains($js, "if (block.name === REUSABLE_BLOCK_TOOL && !supportsReusableBlockInsert(holder)) {"), 'Werkzeug verliert dort die Toolbox');
    T::check(str_contains($js, 'tools = buildTools(uploadUrl, csrfToken, resolvedOptions, holder);'), 'buildTools kennt den Holder');
    // Das Werkzeug bleibt registriert: vorhandene Blöcke laden und gehen beim Speichern nicht verloren.
    T::check(str_contains($js, 'static get toolbox() {') && str_contains($js, 'return undefined;'), 'Toolbox entfällt, Werkzeugklasse bleibt für vorhandene Blöcke');
    $service = $src('core/Services/EditorJs/EditorJsAssetService.php');
    T::check(str_contains($service, "if (\$registryType === 'reusableBlock' && \$contextClass === 'cms-knowledgebase') {"), 'keine Schnellwahl „Vorlagen“ im Wissensdatenbank-Editor');
    T::check(str_contains($src('admin/views/knowledgebase/edit.php'), "'context' => 'cms-knowledgebase'"), 'Kontextname des Wissensdatenbank-Editors stimmt');
    T::check(str_contains($src('assets/js/admin-hub-site-edit.js'), "holder.className = 'editorjs-holder hub-editorjs-holder';"), 'Klassenname des Hub-Holders stimmt');
    T::same('reusableBlock', \CMS\Services\ReusableBlocks\ReusableBlock::TYPE, 'Blocktyp stimmt mit dem Werkzeugnamen überein');
    // Beiträge, Seiten und Block-Vorlagen behalten das Werkzeug: nur die beiden genannten Selektoren sind ausgeschlossen.
    T::same(1, substr_count($js, "var REUSABLE_BLOCK_EXCLUDED_HOLDERS = '"), 'genau eine Ausschlussliste');
    T::check(!str_contains($js, "REUSABLE_BLOCK_EXCLUDED_HOLDERS = '.editorjs-wrap--cms-reusable-block"), 'Vorlagen-Editor ist nicht ausgeschlossen (verschachtelte Vorlagen)');
});

/** SubscriptionManager ohne Datenbank: Einstellungen aus dem Laufzeit-Cache. */
$closureSubscriptionManager = static function (array $settings): \CMS\SubscriptionManager {
    $reflection = new \ReflectionClass(\CMS\SubscriptionManager::class);
    $manager = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('settingsCache');
    $property->setAccessible(true);
    $property->setValue($manager, $settings + ['notification_before_expiry' => '7', 'auto_renewal' => '1', 'grace_period_days' => '3', 'notification_email' => '']);

    return $manager;
};

T::run('FUN-410-28: Verlängerungshinweis nur bei Anbieter-Verlängerung', static function () use ($closureSubscriptionManager): void {
    $manager = $closureSubscriptionManager([]);
    $in20 = gmdate('Y-m-d H:i:s', time() + 20 * 86400);
    $ago1 = gmdate('Y-m-d H:i:s', time() - 86400);

    $provider = $manager->getSubscriptionRenewalNotice(['status' => 'active', 'billing_cycle' => 'monthly', 'provider_subscription_id' => 'sub_1', 'current_period_end' => $in20, 'next_billing_date' => $in20, 'end_date' => $in20]);
    T::check($provider['is_auto_renewal'] === true && $provider['title'] === 'Nächste Verlängerung', 'Abo mit Anbieter-Verlängerung: „Nächste Verlängerung“');

    $invoice = $manager->getSubscriptionRenewalNotice(['status' => 'active', 'billing_cycle' => 'monthly', 'next_billing_date' => $in20, 'end_date' => $in20]);
    T::check($invoice['is_auto_renewal'] === false && $invoice['title'] === 'Aktive Laufzeit' && $invoice['kind'] === 'active_until', 'Rechnungs-Abo ohne Anbieter: „Aktive Laufzeit“, keine Verlängerung');

    $cancelled = $manager->getSubscriptionRenewalNotice(['status' => 'active', 'billing_cycle' => 'monthly', 'provider_subscription_id' => 'sub_1', 'cancelled_at' => gmdate('Y-m-d H:i:s'), 'current_period_end' => $in20, 'end_date' => $in20]);
    T::check($cancelled['is_auto_renewal'] === false && $cancelled['kind'] === 'active_until', 'gekündigtes Anbieter-Abo läuft aus, verlängert nicht');

    $lifetime = $manager->getSubscriptionRenewalNotice(['status' => 'active', 'billing_cycle' => 'lifetime', 'provider_subscription_id' => 'sub_1', 'current_period_end' => $in20]);
    T::check($lifetime['is_auto_renewal'] === false, 'Lifetime verlängert nie');

    $periodOnly = $manager->getSubscriptionRenewalNotice(['status' => 'active', 'billing_cycle' => 'yearly', 'provider_subscription_id' => 'sub_9', 'current_period_end' => $in20]);
    T::check($periodOnly['has_notice'] === true && $periodOnly['due_at'] === $in20 && $periodOnly['is_auto_renewal'] === true, '`current_period_end` allein genügt für Anzeige und Fälligkeit');

    $disabled = $closureSubscriptionManager(['auto_renewal' => '0'])->getSubscriptionRenewalNotice(['status' => 'active', 'billing_cycle' => 'monthly', 'provider_subscription_id' => 'sub_1', 'current_period_end' => $in20]);
    T::check($disabled['is_auto_renewal'] === false, 'globaler Schalter „Auto-Verlängerung“ aus');

    $overdueProvider = $manager->getSubscriptionRenewalNotice(['status' => 'active', 'billing_cycle' => 'monthly', 'provider_subscription_id' => 'sub_1', 'current_period_end' => $ago1, 'next_billing_date' => $ago1, 'end_date' => $ago1]);
    T::check($overdueProvider['is_overdue'] === true && str_contains((string) $overdueProvider['message'], 'längstens 3 Tage'), 'überfällige Anbieter-Verlängerung nennt die wirksame Kulanzzeit');

    $overdueInvoice = $manager->getSubscriptionRenewalNotice(['status' => 'active', 'billing_cycle' => 'monthly', 'next_billing_date' => $ago1, 'end_date' => $ago1]);
    T::check($overdueInvoice['kind'] === 'expired' && !str_contains((string) $overdueInvoice['message'], 'Kulanz') && !str_contains((string) $overdueInvoice['message'], 'längstens'), 'abgelaufenes Rechnungs-Abo verspricht keine Kulanzzeit');

    $noGrace = $closureSubscriptionManager(['grace_period_days' => '0'])->getSubscriptionRenewalNotice(['status' => 'active', 'billing_cycle' => 'monthly', 'provider_subscription_id' => 'sub_1', 'current_period_end' => $ago1]);
    T::check(!str_contains((string) $noGrace['message'], 'längstens'), 'Kulanzzeit 0: kein Hinweis');
});

T::run('FUN-410-28: Kulanzzeit im Zugriff (Quelltext)', static function () use ($src): void {
    $manager = $src('core/SubscriptionManager.php');
    $method = strpos($manager, 'public function getUserSubscription(int $userId)');
    $end = $method === false ? false : strpos($manager, 'public function isLimitEnforcementEnabled', $method);
    $body = $method === false || $end === false ? '' : substr($manager, $method, $end - $method);
    T::check($body !== '', 'getUserSubscription gefunden');
    T::check(str_contains($body, "\$this->getIntSetting('grace_period_days', 3, 0, 365)"), 'Kulanzzeit aus der Einstellung (Standard 3, höchstens 365)');
    T::check(str_contains($body, "us.provider_subscription_id IS NOT NULL AND us.provider_subscription_id <> '' AND us.cancelled_at IS NULL"), 'nur Anbieter-Abos, nicht gekündigt');
    T::check(str_contains($body, 'us.end_date > DATE_SUB(NOW(), INTERVAL {$graceDays} DAY)'), 'Zugang längstens N Tage nach dem Laufzeitende');
    T::check(str_contains($body, "AND us.status = 'active'"), 'nur aktive Abos (erstattete und beendete bleiben ausgeschlossen)');
    T::check(str_contains($body, '$graceDays > 0') && str_contains($body, ": '';"), 'Kulanzzeit 0 lässt keine Klausel entstehen');
});

T::run('Audit-Abschluss: Schema- und Rechte-Marker v26 (ab 4.2.0-Entwicklung: v27)', static function () use ($src): void {
    T::check(in_array(\CMS\SchemaManager::SCHEMA_VERSION, ['v26', 'v27'], true), 'SchemaManager: Marke v26 (4.1.x) oder v27 (Entwicklung 4.2.0)');
    T::same(\CMS\SchemaManager::SCHEMA_VERSION, \CMS\MigrationManager::SCHEMA_VERSION, 'MigrationManager: dieselbe Marke');
    $migration = $src('core/MigrationManager.php');
    T::check(str_contains($migration, '\\CMS\\Services\\Content\\ContentWorkflow::migrateCapabilities($this->db);'), 'capabilities_v26: Übernahme der Veröffentlichungsrechte läuft in der Migration');
    T::check(str_contains($migration, "error_log('MigrationManager: capabilities_v26: '"), 'capabilities_v26: Fehler werden protokolliert');
    T::check(str_contains($src('core/SchemaManager.php'), '\\CMS\\Services\\Content\\ContentWorkflow::migrateCapabilities('), 'auch das Feature-Schema (Neuinstallation) führt capabilities_v26 aus');
    $workflow = $src('core/Services/Content/ContentWorkflow.php');
    T::check(str_contains($workflow, "[['editor', 'posts.publish'], ['editor', 'pages.publish'], ['author', 'posts.publish']]"), 'übernommen werden genau editor (Beiträge, Seiten) und author (Beiträge)');
    $defaults = $src('includes/functions/roles.php');
    T::check(str_contains($defaults, "'blocks.reusable' => true,"), 'blocks.reusable gehört zu den Standardrechten (wirkt ohne eigene Migration)');
    T::check(!str_contains($migration, 'hat nur „admin“ (alle Rechte);') || str_contains($migration, 'Ausnahme: `blocks.reusable`'), 'Kommentar der Migration nennt die Ausnahme blocks.reusable');
});
