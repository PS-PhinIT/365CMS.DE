<?php
/**
 * Admin-Template: Import-Protokoll
 *
 * @var string|null $message         Feedback-Meldung
 * @var string      $msg_type        success|error|warning
 * @var array  $log_entries      Import-Log-Einträge
 * @var string $nonce_download   CSRF-Nonce für Bericht-Download
 * @var string $nonce_cleanup    CSRF-Nonce für Bereinigung
 * @var array  $cleanup_stats    Zähler für Bereinigung
 */

if (!defined('ABSPATH')) {
    exit;
}

$esc_nonce_cleanup = htmlspecialchars($nonce_cleanup ?? '');
?>
<div class="cms-importer-wrap ci-admin-shell">

    <div class="admin-page-header">
        <div>
            <h2>Import-Protokoll</h2>
            <p>Übersicht aller WordPress-Imports inklusive Meta-Berichte, Laufzeiten und Ergebniszahlen.</p>
        </div>
        <div class="header-actions">
            <a href="/admin/plugins/cms-importer/cms-importer-import" class="btn btn-secondary"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Zum Import</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="ci-notice ci-notice--<?php echo htmlspecialchars($msg_type ?? 'success'); ?>">
            <?php echo htmlspecialchars((string) $message); ?>
        </div>
    <?php endif; ?>

    <div class="admin-card cms-importer-card">
        <div class="cms-importer-card__actions">
            <button type="button"
                    class="ci-btn ci-btn--ghost-danger js-cleanup-trigger"
                    data-cleanup-action="cleanup_history"
                    data-cleanup-title="Importer-Verlauf l&ouml;schen"
                    data-cleanup-body="Es werden alle Import-Protokolle, Import-Mappings, Import-Meta-Daten und gespeicherten Bericht-Dateien des Plugins entfernt. Dateien in den Import-Ordnern bleiben erhalten.">
                🗑️ Verlauf l&ouml;schen
            </button>
        </div>

        <div class="ci-cleanup-inline-stats">
            <span><strong><?php echo (int) ($cleanup_stats['logs'] ?? 0); ?></strong> Protokolle</span>
            <span><strong><?php echo (int) ($cleanup_stats['mappings'] ?? 0); ?></strong> Mappings</span>
            <span><strong><?php echo (int) ($cleanup_stats['meta'] ?? 0); ?></strong> Meta-Eintr&auml;ge</span>
            <span><strong><?php echo (int) ($cleanup_stats['reports'] ?? 0); ?></strong> Berichte</span>
        </div>

        <p class="ci-muted" style="margin:0 0 18px;">Hinweis: Auf der Import-Seite l&ouml;scht der Reset jetzt bewusst <strong>alle</strong> Beitr&auml;ge und Seiten im CMS &ndash; nicht nur importierte Inhalte.</p>

        <?php if (empty($log_entries)): ?>
            <div class="cms-importer-empty">
                <p>Noch keine Imports durchgeführt.</p>
            </div>
        <?php else: ?>
        <div class="users-table-container">
        <table class="users-table cms-importer-table cms-importer-table--full">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Dateiname</th>
                    <th>Typ</th>
                    <th>Gesamt</th>
                    <th>Importiert</th>
                    <th>Übersprungen</th>
                    <th>Fehler</th>
                    <th>Gestartet</th>
                    <th>Beendet</th>
                    <th>Bilder</th>
                    <th>Meta-Bericht</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($log_entries as $log): ?>
                <tr>
                    <td><?php echo (int) $log->id; ?></td>
                    <td>
                        <code class="cms-importer-code"><?php echo htmlspecialchars($log->filename); ?></code>
                    </td>
                    <td>
                        <span class="cms-importer-badge cms-importer-badge--<?php echo htmlspecialchars($log->import_type ?? 'mixed'); ?>">
                            <?php echo htmlspecialchars($log->import_type ?? 'mixed'); ?>
                        </span>
                    </td>
                    <td><?php echo (int) $log->total; ?></td>
                    <td class="cms-importer-table__success"><?php echo (int) $log->imported; ?></td>
                    <td class="cms-importer-table__warning"><?php echo (int) $log->skipped; ?></td>
                    <td class="cms-importer-table__error"><?php echo (int) $log->errors; ?></td>
                    <td><?php echo htmlspecialchars(substr($log->started_at ?? '', 0, 16)); ?></td>
                    <td>
                        <?php if (!empty($log->finished_at)): ?>
                            <?php echo htmlspecialchars(substr($log->finished_at, 0, 16)); ?>
                        <?php else: ?>
                            <span class="cms-importer-muted">Läuft…</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int) ($log->images_downloaded ?? 0); ?></td>
                    <td>
                        <?php if (!empty($log->meta_report_path)): ?>
                            <a href="/admin/plugins/cms-importer/cms-importer?action=download_report&amp;log_id=<?php echo (int) $log->id; ?>&amp;_nonce=<?php echo htmlspecialchars($nonce_download ?? ''); ?>&amp;format=html"
                               class="cms-importer-link">
                                📄 Bericht
                            </a>
                            <span class="cms-importer-muted"> / </span>
                            <a href="/admin/plugins/cms-importer/cms-importer?action=download_report&amp;log_id=<?php echo (int) $log->id; ?>&amp;_nonce=<?php echo htmlspecialchars($nonce_download ?? ''); ?>&amp;format=md"
                               class="cms-importer-link">.md</a>
                        <?php else: ?>
                            <span class="cms-importer-muted">Keine Metas</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="ci-modal" id="js-cleanup-modal" hidden>
    <div class="ci-modal__backdrop" data-close-cleanup-modal></div>
    <div class="ci-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="js-cleanup-modal-title">
        <div class="ci-modal__header">
            <h3 id="js-cleanup-modal-title">Bereinigung best&auml;tigen</h3>
            <button type="button" class="ci-modal__close" data-close-cleanup-modal aria-label="Modal schlie&szlig;en">&times;</button>
        </div>
        <div class="ci-modal__body">
            <p id="js-cleanup-modal-text">Diese Aktion kann nicht r&uuml;ckg&auml;ngig gemacht werden.</p>
            <label class="ci-option ci-option--stack" style="margin-top:1rem;">
                <input type="checkbox" name="reset_cleanup_sequences" id="js-cleanup-reset-sequences" value="1">
                <span>Import-Log-/Mapping-IDs optional mit zur&uuml;cksetzen, wenn die jeweilige Import-Tabelle nach der Bereinigung leer ist</span>
            </label>
            <p class="ci-options__hint" style="margin:0.5rem 0 0;">Vor allem beim Verlauf l&ouml;schen werden damit Log-, Mapping- und Meta-Z&auml;hler wieder auf die erste ID gesetzt.</p>
        </div>
        <div class="ci-modal__footer">
            <button type="button" class="ci-btn ci-btn--ghost" data-close-cleanup-modal>Abbrechen</button>
            <form method="POST" id="js-cleanup-form">
                <input type="hidden" name="cms_admin_action" id="js-cleanup-action" value="">
                <input type="hidden" name="_cleanup_nonce" value="<?php echo $esc_nonce_cleanup; ?>">
                <button type="submit" class="ci-btn ci-btn--danger" id="js-cleanup-submit">Jetzt ausf&uuml;hren</button>
            </form>
        </div>
    </div>
</div>
