<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Webhooks: Empfänger (anlegen, ändern, testen, Geheimnis neu, löschen) und Zustellprotokoll. Das Geheimnis steht nur
 * einmal in der Erfolgsmeldung; Formulare zeigen es nie.
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$whData = is_array($data ?? null) ? $data : [];
$whEndpoints = (array) ($whData['endpoints'] ?? []);
$whDeliveries = (array) ($whData['deliveries'] ?? []);
$whEvents = (array) ($whData['events'] ?? []);
$whE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$whNames = [];
foreach ($whEndpoints as $whEndpoint) {
    $whNames[(int) $whEndpoint['id']] = (string) $whEndpoint['name'];
}
$whStatus = [
    'pending' => ['Ausstehend', 'bg-azure-lt text-azure'],
    'delivered' => ['Zugestellt', 'bg-green-lt text-green'],
    'failed' => ['Fehlgeschlagen', 'bg-red-lt text-red'],
    'dead' => ['Aufgegeben', 'bg-secondary-lt'],
];
$whEventFields = static function (string $prefix, array $selected) use ($whEvents, $whE): string {
    $html = '<fieldset><legend class="form-label">Ereignisse</legend><div class="row g-1">';
    foreach ($whEvents as $event => $label) {
        $id = $prefix . preg_replace('/[^a-z0-9]/', '', (string) $event);
        $html .= '<div class="col-md-6"><label class="form-check" for="' . $whE($id) . '"><input type="checkbox" class="form-check-input" id="' . $whE($id)
            . '" name="events[]" value="' . $whE($event) . '"' . (in_array($event, $selected, true) ? ' checked' : '') . '><span class="form-check-label">'
            . $whE($label) . ' <code class="small">' . $whE($event) . '</code></span></label></div>';
    }

    return $html . '</div></fieldset>';
};
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="page-pretitle">System</div>
        <h2 class="page-title">Webhooks</h2>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-7">
                <?php if ($whEndpoints === []): ?>
                    <div class="card mb-4"><div class="card-body"><p class="text-secondary mb-0">Noch keine Empfänger. Lege rechts den ersten an.</p></div></div>
                <?php endif; ?>
                <?php foreach ($whEndpoints as $whEndpoint): ?>
                    <?php $whId = (int) $whEndpoint['id']; ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title"><?= $whE($whEndpoint['name']) ?></h3>
                            <div class="card-actions">
                                <span class="badge <?= $whEndpoint['is_active'] ? 'bg-green-lt text-green' : 'bg-secondary-lt' ?>"><?= $whEndpoint['is_active'] ? 'Aktiv' : 'Deaktiviert' ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!$whEndpoint['is_active'] && $whEndpoint['disabled_reason'] !== ''): ?>
                                <div class="alert alert-warning" role="status">Automatisch deaktiviert: <?= $whE($whEndpoint['disabled_reason']) ?></div>
                            <?php endif; ?>
                            <form method="post" action="/admin/webhooks">
                                <input type="hidden" name="csrf_token" value="<?= $whE($csrfToken) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="endpoint_id" value="<?= $whId ?>">
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <label class="form-label" for="whName<?= $whId ?>">Name</label>
                                        <input type="text" class="form-control" id="whName<?= $whId ?>" name="name" maxlength="100" required value="<?= $whE($whEndpoint['name']) ?>">
                                    </div>
                                    <div class="col-md-7">
                                        <label class="form-label" for="whUrl<?= $whId ?>">URL (HTTPS)</label>
                                        <input type="url" class="form-control" id="whUrl<?= $whId ?>" name="url" maxlength="500" required value="<?= $whE($whEndpoint['url']) ?>">
                                    </div>
                                    <div class="col-12"><?= $whEventFields('whEv' . $whId . '_', $whEndpoint['events']) ?></div>
                                    <div class="col-12">
                                        <label class="form-check">
                                            <input type="checkbox" class="form-check-input" name="is_active" value="1"<?= $whEndpoint['is_active'] ? ' checked' : '' ?>>
                                            <span class="form-check-label">Aktiv (setzt den Fehlerzähler zurück)</span>
                                        </label>
                                        <div class="form-hint">Fehlschläge in Folge: <?= (int) $whEndpoint['consecutive_failures'] ?> (bei 20 wird automatisch deaktiviert).</div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary mt-3">Speichern</button>
                            </form>
                        </div>
                        <div class="card-footer d-flex flex-wrap gap-2">
                            <?php foreach (['test' => ['Test senden', 'btn-outline-primary'], 'regenerate' => ['Geheimnis neu erzeugen', 'btn-outline-secondary'], 'delete' => ['Löschen', 'btn-outline-danger']] as $whAction => [$whLabel, $whClass]): ?>
                                <form method="post" action="/admin/webhooks">
                                    <input type="hidden" name="csrf_token" value="<?= $whE($csrfToken) ?>">
                                    <input type="hidden" name="action" value="<?= $whE($whAction) ?>">
                                    <input type="hidden" name="endpoint_id" value="<?= $whId ?>">
                                    <button type="submit" class="btn btn-sm <?= $whE($whClass) ?>"><?= $whE($whLabel) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="card">
                    <div class="card-header"><h3 class="card-title">Zustellprotokoll</h3><span class="card-subtitle ms-2 text-secondary">letzte 50, ohne Inhalt der Antworten</span></div>
                    <?php if ($whDeliveries === []): ?>
                        <div class="card-body"><p class="text-secondary mb-0">Noch keine Zustellungen.</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table">
                                <thead><tr><th scope="col">Zeit</th><th scope="col">Empfänger</th><th scope="col">Ereignis</th><th scope="col">Status</th><th scope="col">Versuche</th></tr></thead>
                                <tbody>
                                    <?php foreach ($whDeliveries as $whDelivery): ?>
                                        <?php [$whLabel, $whClass] = $whStatus[$whDelivery['status']] ?? [$whDelivery['status'], 'bg-secondary-lt']; ?>
                                        <tr>
                                            <td class="text-nowrap"><?= $whE($whDelivery['created_at']) ?></td>
                                            <td><?= $whE($whNames[$whDelivery['endpoint_id']] ?? ('#' . $whDelivery['endpoint_id'])) ?></td>
                                            <td><code><?= $whE($whDelivery['event']) ?></code></td>
                                            <td>
                                                <span class="badge <?= $whE($whClass) ?>"><?= $whE($whLabel) ?></span>
                                                <?php if ($whDelivery['response_status'] > 0): ?><span class="small text-secondary">HTTP <?= (int) $whDelivery['response_status'] ?></span><?php endif; ?>
                                                <?php if ($whDelivery['last_error'] !== '' && $whDelivery['status'] !== 'delivered'): ?><div class="small text-danger"><?= $whE($whDelivery['last_error']) ?></div><?php endif; ?>
                                            </td>
                                            <td><?= (int) $whDelivery['attempts'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-xl-5">
                <form method="post" action="/admin/webhooks" class="card mb-4">
                    <input type="hidden" name="csrf_token" value="<?= $whE($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="card-header"><h3 class="card-title">Neuer Empfänger</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="whNewName">Name</label>
                            <input type="text" class="form-control" id="whNewName" name="name" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="whNewUrl">URL (HTTPS)</label>
                            <input type="url" class="form-control" id="whNewUrl" name="url" maxlength="500" required placeholder="https://hooks.example.com/365cms">
                        </div>
                        <?= $whEventFields('whNew_', []) ?>
                    </div>
                    <div class="card-footer"><button type="submit" class="btn btn-primary">Anlegen</button></div>
                </form>

                <div class="card">
                    <div class="card-header"><h3 class="card-title">So prüft der Empfänger die Signatur</h3></div>
                    <div class="card-body small">
                        <p>Jede Zustellung ist ein <code>POST</code> mit JSON (<code>id</code>, <code>event</code>, <code>created_at</code>, <code>data</code>) und den Kopfzeilen <code>X-365CMS-Event</code>, <code>X-365CMS-Delivery</code>, <code>X-365CMS-Timestamp</code> und <code>X-365CMS-Signature</code>.</p>
                        <p class="mb-1">Signatur: <code>v1=</code> + HMAC-SHA256 (hex) über <code>Zeitstempel + "." + Body</code> mit dem Geheimnis. Zeitstempel älter als 5 Minuten ablehnen, Vergleich zeitkonstant.</p>
                        <p class="mb-0">Antwort 2xx = zugestellt. Sonst bis zu 5 Versuche (nach 1, 5, 30, 120, 720 Minuten). Antwort 410 deaktiviert den Empfänger sofort, 20 Fehlschläge in Folge ebenfalls.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
