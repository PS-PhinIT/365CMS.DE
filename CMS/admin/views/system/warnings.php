<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

$warnings = is_array($data['warnings'] ?? null) ? $data['warnings'] : [];
$suppressedWarnings = is_array($data['suppressed_warnings'] ?? null) ? $data['suppressed_warnings'] : [];
$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$sourceSummary = is_array($data['source_summary'] ?? null) ? $data['source_summary'] : [];
$snoozeDays = is_array($data['snooze_days'] ?? null) ? $data['snooze_days'] : [1, 3, 7, 14, 30];
$notes = is_array($data['notes'] ?? null) ? $data['notes'] : [];

if (!function_exists('cms_admin_warning_center_badge_class')) {
    function cms_admin_warning_center_badge_class(string $severity): string
    {
        return $severity === 'critical' ? 'danger' : 'warning';
    }
}

if (!function_exists('cms_admin_warning_center_card_class')) {
    function cms_admin_warning_center_card_class(string $severity): string
    {
        return $severity === 'critical' ? 'border-danger' : 'border-warning';
    }
}

if (!function_exists('cms_admin_warning_center_suppression_label')) {
    function cms_admin_warning_center_suppression_label(array $suppression): string
    {
        $mode = (string)($suppression['mode'] ?? 'active');
        if ($mode === 'ignored') {
            return 'Ignoriert';
        }

        $untilLabel = trim((string)($suppression['until_label'] ?? ''));
        if ($mode === 'snoozed' && $untilLabel !== '') {
            return 'Erinnert wieder ab ' . $untilLabel;
        }

        return 'Aktiv';
    }
}
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Diagnose</div>
                <h2 class="page-title">Warnzentrale</h2>
                <div class="text-secondary mt-1">Bündelt aktive Warnungen aus Performance, Security, Diagnose, Updates und Recht an einer Stelle — ohne neue GET-Mutationen und ohne Token-Gymnastik in URLs.</div>
            </div>
            <div class="col-auto d-flex gap-2 flex-wrap">
                <a href="<?php echo htmlspecialchars('/admin/diagnose', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Diagnose öffnen</a>
                <a href="<?php echo htmlspecialchars('/admin/updates', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Updates öffnen</a>
                <a href="<?php echo htmlspecialchars('/admin/data-requests', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Datenanfragen öffnen</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Aktive Warnungen</div>
                        <div class="h1 mb-0"><?php echo (int)($summary['active_total'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Kritisch</div>
                        <div class="h1 mb-0 text-danger"><?php echo (int)($summary['critical_total'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Unterdrückt</div>
                        <div class="h1 mb-0 text-secondary"><?php echo (int)($summary['suppressed_total'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Betroffene Bereiche</div>
                        <div class="h1 mb-0"><?php echo (int)($summary['source_total'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-cards mb-4">
            <div class="col-12 col-xl-8">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title mb-0">Aktive Warnungen</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($warnings === []): ?>
                            <div class="empty-state empty-state-icon">
                                <div class="empty-state-icon bg-success-lt text-success">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10"/></svg>
                                </div>
                                <p class="empty-title">Keine aktiven Warnungen</p>
                                <p class="empty-subtitle text-secondary">Gerade ist alles erstaunlich ordentlich. Das passiert auch einem CMS nicht jeden Tag.</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-4">
                                <?php foreach ($warnings as $warning): ?>
                                    <?php
                                    $severity = (string)($warning['severity'] ?? 'warning');
                                    $badgeClass = cms_admin_warning_center_badge_class($severity);
                                    $cardClass = cms_admin_warning_center_card_class($severity);
                                    $warningDetails = is_array($warning['details'] ?? null) ? $warning['details'] : [];
                                    ?>
                                    <div class="card <?php echo htmlspecialchars($cardClass, ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="card-body">
                                            <div class="d-flex flex-column flex-lg-row gap-3 justify-content-between align-items-start mb-3">
                                                <div>
                                                    <div class="d-flex gap-2 flex-wrap mb-2">
                                                        <span class="badge bg-<?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>-lt text-<?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($warning['severity_label'] ?? 'Warnung'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span class="badge bg-secondary-lt text-secondary"><?php echo htmlspecialchars((string)($warning['source_label'] ?? 'System'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                    <h3 class="card-title mb-1"><?php echo htmlspecialchars((string)($warning['title'] ?? 'Warnung'), ENT_QUOTES, 'UTF-8'); ?></h3>
                                                    <div class="text-secondary"><?php echo htmlspecialchars((string)($warning['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                </div>
                                                <?php if (!empty($warning['action_url'])): ?>
                                                    <a href="<?php echo htmlspecialchars((string)$warning['action_url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-<?php echo $severity === 'critical' ? 'danger' : 'warning'; ?>">
                                                        <?php echo htmlspecialchars((string)($warning['action_label'] ?? 'Lösen / öffnen'), ENT_QUOTES, 'UTF-8'); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($warningDetails !== []): ?>
                                                <div class="mb-3">
                                                    <div class="small text-secondary mb-2">Kontext</div>
                                                    <ul class="mb-0 small ps-3">
                                                        <?php foreach ($warningDetails as $detail): ?>
                                                            <li><?php echo htmlspecialchars((string)$detail, ENT_QUOTES, 'UTF-8'); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>

                                            <div class="row g-3">
                                                <div class="col-lg-6">
                                                    <form method="post" class="border rounded p-3 h-100">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="ignore_warning_center_warning">
                                                        <input type="hidden" name="warning_id" value="<?php echo htmlspecialchars((string)($warning['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <label class="form-label" for="ignore-reason-<?php echo htmlspecialchars((string)($warning['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">Ignorieren mit Begründung</label>
                                                        <input
                                                            id="ignore-reason-<?php echo htmlspecialchars((string)($warning['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                            type="text"
                                                            name="warning_reason"
                                                            class="form-control mb-2"
                                                            maxlength="240"
                                                            placeholder="Warum ist diese Warnung aktuell bewusst toleriert?"
                                                            required
                                                        >
                                                        <button type="submit" class="btn btn-outline-secondary w-100">Warnung ignorieren</button>
                                                    </form>
                                                </div>
                                                <div class="col-lg-6">
                                                    <form method="post" class="border rounded p-3 h-100">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="snooze_warning_center_warning">
                                                        <input type="hidden" name="warning_id" value="<?php echo htmlspecialchars((string)($warning['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <label class="form-label" for="snooze-days-<?php echo htmlspecialchars((string)($warning['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">Später erinnern</label>
                                                        <select id="snooze-days-<?php echo htmlspecialchars((string)($warning['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" name="warning_snooze_days" class="form-select mb-2">
                                                            <?php foreach ($snoozeDays as $day): ?>
                                                                <option value="<?php echo (int)$day; ?>"><?php echo (int)$day; ?> Tag(e)</option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input
                                                            type="text"
                                                            name="warning_snooze_note"
                                                            aria-label="Hinweis für die Wiedervorlage"
                                                            class="form-control mb-2"
                                                            maxlength="240"
                                                            placeholder="Optionaler Hinweis für die Wiedervorlage"
                                                        >
                                                        <button type="submit" class="btn btn-outline-primary w-100">Wiedervorlage speichern</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title mb-0">Bereiche im Überblick</h3>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if ($sourceSummary === []): ?>
                            <div class="list-group-item text-secondary">Keine aktiven Bereichswarnungen.</div>
                        <?php else: ?>
                            <?php foreach ($sourceSummary as $source): ?>
                                <div class="list-group-item">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars((string)($source['label'] ?? 'System'), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="text-secondary small"><?php echo (int)($source['active_count'] ?? 0); ?> aktiv · <?php echo (int)($source['critical_count'] ?? 0); ?> kritisch</div>
                                        </div>
                                        <span class="badge bg-<?php echo (int)($source['critical_count'] ?? 0) > 0 ? 'danger' : 'secondary'; ?>-lt text-<?php echo (int)($source['critical_count'] ?? 0) > 0 ? 'danger' : 'secondary'; ?>">
                                            <?php echo (int)($source['active_count'] ?? 0); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title mb-0">Hinweise</h3>
                    </div>
                    <div class="card-body">
                        <ul class="mb-0 small ps-3">
                            <?php foreach ($notes as $note): ?>
                                <li><?php echo htmlspecialchars((string)$note, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">Unterdrückte Warnungen</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead>
                        <tr>
                            <th>Bereich</th>
                            <th>Warnung</th>
                            <th>Status</th>
                            <th>Begründung</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($suppressedWarnings === []): ?>
                            <tr>
                                <td colspan="5" class="text-center text-secondary py-4">Aktuell sind keine Warnungen ignoriert oder vertagt.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($suppressedWarnings as $warning): ?>
                                <?php $suppression = is_array($warning['suppression'] ?? null) ? $warning['suppression'] : []; ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($warning['source_label'] ?? 'System'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-secondary small"><?php echo htmlspecialchars((string)($warning['severity_label'] ?? 'Warnung'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($warning['title'] ?? 'Warnung'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-secondary small"><?php echo htmlspecialchars((string)($warning['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-lt text-secondary"><?php echo htmlspecialchars(cms_admin_warning_center_suppression_label($suppression), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($suppression['reason'])): ?>
                                            <div><?php echo htmlspecialchars((string)$suppression['reason'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php else: ?>
                                            <span class="text-secondary">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php if (!empty($warning['action_url'])): ?>
                                                <a href="<?php echo htmlspecialchars((string)$warning['action_url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary"><?php echo htmlspecialchars((string)($warning['action_label'] ?? 'Öffnen'), ENT_QUOTES, 'UTF-8'); ?></a>
                                            <?php endif; ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="restore_warning_center_warning">
                                                <input type="hidden" name="warning_id" value="<?php echo htmlspecialchars((string)($warning['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Wieder aktivieren</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
