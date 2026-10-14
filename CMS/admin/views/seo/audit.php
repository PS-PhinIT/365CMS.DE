<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$overview = $data['overview'] ?? [];
$audit = $data['audit'] ?? [];
$content = $audit['rows'] ?? [];
$scoreColors = ['good' => 'success', 'warning' => 'warning', 'bad' => 'danger'];
$scoreLabels = ['good' => 'Gut', 'warning' => 'Warnung', 'bad' => 'Kritisch'];
$twitterCardOptions = ['summary_large_image', 'summary'];
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">SEO</div>
                <h2 class="page-title">SEO Audit</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php
            $alertData = is_array($alert ?? null) ? $alert : [];
            require dirname(__DIR__) . '/partials/flash-alert.php';
            ?>
        <?php endif; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Geprüfte Inhalte</div><div class="h1 mb-0"><?= (int)($overview['total'] ?? 0) ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Warnungen</div><div class="h1 mb-0 text-warning"><?= (int)($audit['warning_count'] ?? 0) ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Kritisch</div><div class="h1 mb-0 text-danger"><?= (int)($audit['critical_count'] ?? 0) ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Ø Score</div><div class="h1 mb-0"><?= (int)($overview['average_score'] ?? 0) ?></div></div></div></div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Inhalte & Bulk-Metadaten</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Inhalt</th>
                            <th>Meta</th>
                            <th>Social & Technik</th>
                            <th>Score</th>
                            <th>Probleme</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($content as $item): ?>
                            <?php
                            // FIX: View defensiv gegen unvollständige Audit-Datensätze absichern.
                            $itemScore = (string)($item['seo_score'] ?? 'warning');
                            if (!array_key_exists($itemScore, $scoreColors)) {
                                $itemScore = 'warning';
                            }
                            $itemScoreValue = (int)($item['seo_score_value'] ?? (($item['analysis']['score'] ?? 0)));
                            $itemIssues = array_values((array)($item['seo_issues'] ?? []));
                            $itemContentType = (string)($item['type'] ?? '');
                            $itemSchemaType = \CMS\Services\SEO\SeoSchemaRenderer::effectiveTypeFor((string)($item['schema_type'] ?? ''), $itemContentType);
                            $itemSchemaSelected = \CMS\Services\SEO\SeoSchemaRenderer::selectableTypeFor($itemSchemaType, $itemContentType);
                            $itemFieldPrefix = 'seo-audit-' . preg_replace('/[^a-z0-9_-]/i', '', $itemContentType) . '-' . (int)($item['id'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string)($item['title'] ?? '')) ?></div>
                                    <div class="text-secondary small"><?= htmlspecialchars((string)($item['type'] ?? '')) ?> · <?= htmlspecialchars((string)($item['slug'] ?? '')) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars((string)($item['resolved_meta_title'] ?? '')) ?></div>
                                    <div class="text-secondary small"><?= htmlspecialchars((string)($item['resolved_meta_description'] ?? '')) ?></div>
                                </td>
                                <td>
                                    <div class="small"><strong>Keyphrase:</strong> <?= htmlspecialchars((string)($item['focus_keyphrase'] ?? '—')) ?></div>
                                    <div class="small"><strong>Keywords:</strong> <?= htmlspecialchars((string)($item['keywords'] ?? '—')) ?></div>
                                    <div class="small"><strong>Canonical:</strong> <?= htmlspecialchars((string)($item['canonical_url'] ?? 'automatisch')) ?></div>
                                    <div class="small"><strong>Schema:</strong> <?= htmlspecialchars($itemSchemaType) ?></div>
                                </td>
                                <td><span class="badge bg-<?= htmlspecialchars($scoreColors[$itemScore] ?? 'secondary') ?>"><?= $itemScoreValue ?> · <?= htmlspecialchars($scoreLabels[$itemScore] ?? '?') ?></span></td>
                                <td>
                                    <?php foreach (array_slice($itemIssues, 0, 4) as $issue): ?>
                                        <div class="small text-warning"><strong><?= htmlspecialchars((string)($issue['msg'] ?? '')) ?></strong> <span class="text-secondary">· <?= htmlspecialchars((string)($issue['detail'] ?? '')) ?></span></div>
                                    <?php endforeach; ?>
                                    <?php if ($itemIssues === []): ?><span class="text-success small">✓ Keine offenen Punkte</span><?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <details>
                                        <summary class="btn btn-outline-secondary btn-sm">Bearbeiten</summary>
                                        <form method="post" class="mt-3" style="min-width:340px;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($csrfToken ?? '')) ?>">
                                            <input type="hidden" name="action" value="save_audit_item">
                                            <input type="hidden" name="content_type" value="<?= htmlspecialchars((string)($item['type'] ?? '')) ?>">
                                            <input type="hidden" name="content_id" value="<?= (int)($item['id'] ?? 0) ?>">
                                            <div class="row g-2 text-start">
                                                <div class="col-12"><label for="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-meta-title" class="form-label small">Meta-Titel</label><input id="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-meta-title" class="form-control form-control-sm" type="text" name="meta_title" value="<?= htmlspecialchars((string)($item['meta_title'] ?? '')) ?>"></div>
                                                <div class="col-12"><label for="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-meta-description" class="form-label small">Meta-Beschreibung</label><textarea id="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-meta-description" class="form-control form-control-sm" name="meta_description" rows="3"><?= htmlspecialchars((string)($item['meta_description'] ?? '')) ?></textarea></div>
                                                <div class="col-12"><label for="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-focus-keyphrase" class="form-label small">Fokus-Keyphrase</label><input id="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-focus-keyphrase" class="form-control form-control-sm" type="text" name="focus_keyphrase" value="<?= htmlspecialchars((string)($item['focus_keyphrase'] ?? '')) ?>"></div>
                                                <div class="col-12"><label for="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-keywords" class="form-label small">SEO-Tags / Keywords</label><input id="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-keywords" class="form-control form-control-sm" type="text" name="keywords" value="<?= htmlspecialchars((string)($item['keywords'] ?? '')) ?>" placeholder="Kommagetrennte Tags"></div>
                                                <div class="col-12"><label for="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-canonical" class="form-label small">Canonical</label><input id="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-canonical" class="form-control form-control-sm" type="text" name="canonical_url" value="<?= htmlspecialchars((string)($item['canonical_url'] ?? '')) ?>"></div>
                                                <div class="col-6">
                                                    <label for="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-schema-type" class="form-label small">Schema</label>
                                                    <select id="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-schema-type" class="form-select form-select-sm" name="schema_type">
                                                        <?php foreach (\CMS\Services\SEO\SeoSchemaRenderer::typeOptionsFor($itemContentType) as $schemaTypeOption): ?>
                                                            <option value="<?= htmlspecialchars($schemaTypeOption) ?>" <?= $itemSchemaSelected === $schemaTypeOption ? 'selected' : '' ?>><?= htmlspecialchars($schemaTypeOption) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-6">
                                                    <label for="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-twitter-card" class="form-label small">Twitter Card</label>
                                                    <select id="<?= htmlspecialchars($itemFieldPrefix, ENT_QUOTES) ?>-twitter-card" class="form-select form-select-sm" name="twitter_card">
                                                        <?php foreach ($twitterCardOptions as $twitterCardOption): ?>
                                                            <option value="<?= htmlspecialchars($twitterCardOption) ?>" <?= ((string)($item['twitter_card'] ?? 'summary_large_image') === $twitterCardOption) ? 'selected' : '' ?>><?= htmlspecialchars($twitterCardOption) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-6"><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="robots_index" value="1" <?= !empty($item['robots_index']) ? 'checked' : '' ?>><span class="form-check-label">index</span></label></div>
                                                <div class="col-6"><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="robots_follow" value="1" <?= !empty($item['robots_follow']) ? 'checked' : '' ?>><span class="form-check-label">follow</span></label></div>
                                                <div class="col-12"><button type="submit" class="btn btn-primary btn-sm w-100">Speichern</button></div>
                                            </div>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
