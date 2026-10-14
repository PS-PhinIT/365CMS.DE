<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_MEMBER_VIEW')) {
    exit;
}

$settings = $data['settings'] ?? [];
$profileFields = $data['profileFields'] ?? [];
$selectedProfileFields = array_map('strval', (array)($settings['profile_fields'] ?? []));
$requiredProfileFields = array_map('strval', (array)($settings['required_profile_fields'] ?? ['username', 'email']));
$customProfileFields = is_array($settings['custom_profile_fields'] ?? null) ? $settings['custom_profile_fields'] : [];
$profileFieldCompatibility = is_array($data['profileFieldCompatibility'] ?? null) ? $data['profileFieldCompatibility'] : [];
$compatibilityAvailable = !empty($profileFieldCompatibility['available']);
$currentIncompleteSamples = is_array($profileFieldCompatibility['current_incomplete_samples'] ?? null) ? $profileFieldCompatibility['current_incomplete_samples'] : [];
?>
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Mitglieder &amp; Zugriff</div>
                <h2 class="page-title">Member Dashboard – Profil-Felder</h2>
                <div class="text-muted mt-1">Pflege sichtbare Profilinformationen und steuere zusätzliche Menüpunkte für Mitglieder.</div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="settings_section" value="profile-fields">

            <div class="row row-cards mb-4">
                <div class="col-lg-8">
                    <div class="card mb-4" id="profileFieldCompatibilityCard">
                        <div class="card-header d-flex justify-content-between align-items-center gap-2">
                            <h3 class="card-title mb-0">Kompatibilitätsvorschau</h3>
                            <span class="badge bg-blue-lt text-blue">Read-only</span>
                        </div>
                        <div class="card-body">
                            <?php if ($compatibilityAvailable): ?>
                                <script type="application/json" id="profileFieldCompatibilityData"><?php echo json_encode($profileFieldCompatibility, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <div class="text-secondary small">Aktive Konten</div>
                                        <div class="h2 mb-0"><?php echo (int)($profileFieldCompatibility['active_user_count'] ?? 0); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-secondary small">Aktuell unvollständig</div>
                                        <div class="h2 mb-0 text-warning" id="profileCompatibilityCurrentIncomplete"><?php echo (int)($profileFieldCompatibility['current_incomplete_count'] ?? 0); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-secondary small">Onboarding-Pflicht</div>
                                        <div>
                                            <span class="badge <?php echo !empty($profileFieldCompatibility['onboarding_require_profile_completion']) ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary'; ?>">
                                                <?php echo !empty($profileFieldCompatibility['onboarding_require_profile_completion']) ? 'Aktiv' : 'Optional'; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="alert alert-secondary mb-3" role="status" aria-live="polite" id="profileCompatibilityLiveSummary">
                                    Wähle Profilfelder aus, um vor dem Speichern zu sehen, welche zusätzlichen Felder bestehende Profile unvollständig machen können.
                                </div>

                                <div class="small fw-semibold mb-2">Aktuell betroffene Beispielkonten</div>
                                <?php if ($currentIncompleteSamples === []): ?>
                                    <p class="text-secondary small mb-3">Für die aktuell gespeicherte Feldauswahl sind keine Beispielkonten mit fehlenden Profilwerten bekannt.</p>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1 mb-3">
                                        <?php foreach ($currentIncompleteSamples as $sample): ?>
                                            <span class="badge bg-yellow-lt text-yellow"><?php echo htmlspecialchars((string)($sample['label'] ?? 'Benutzer')); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="small fw-semibold mb-2">Neue Feldrisiken vor dem Speichern</div>
                                <div class="d-flex flex-column gap-2" id="profileCompatibilityFieldRisks"></div>
                                <div class="form-hint mt-3">Die Vorschau schreibt keine Daten und versendet keine Nachrichten. Sie zählt aktive Konten aggregiert und zeigt nur begrenzte Beispielkonten für Support-Orientierung.</div>
                            <?php else: ?>
                                <div class="alert alert-warning mb-0" role="alert">
                                    <?php echo htmlspecialchars((string)($profileFieldCompatibility['message'] ?? 'Die Kompatibilitätsvorschau ist aktuell nicht verfügbar. Die Profilfeld-Speicherung bleibt möglich.')); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">Standard-Profilfelder</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info" role="note">
                                Standardmäßig stehen Vorname, Nachname, Benutzername, Geburtsdatum, Mailadresse, Website und SocialMedia bereit. Pflichtfelder sind nur <strong>Benutzername</strong> und <strong>Mailadresse</strong>; weitere Felder können optional oder verpflichtend markiert werden.
                            </div>
                            <div class="row row-cards">
                                <?php foreach ($profileFields as $key => $field): ?>
                                    <?php if (!empty($field['custom'])) { continue; } ?>
                                    <?php $isLockedRequired = !empty($field['locked']) || in_array($key, ['username', 'email'], true); ?>
                                    <div class="col-md-6">
                                        <div class="card card-sm h-100">
                                            <div class="card-body">
                                                <div class="form-check mb-2">
                                                    <input type="checkbox" class="form-check-input" id="profileField-<?php echo htmlspecialchars((string)$key, ENT_QUOTES); ?>" name="profile_fields[<?php echo htmlspecialchars((string)$key); ?>]" value="1" data-profile-field-checkbox data-profile-field-key="<?php echo htmlspecialchars((string)$key, ENT_QUOTES); ?>" <?php echo in_array($key, $selectedProfileFields, true) ? 'checked' : ''; ?> <?php echo $isLockedRequired ? 'checked disabled' : ''; ?>>
                                                    <?php if ($isLockedRequired): ?>
                                                        <input type="hidden" name="profile_fields[<?php echo htmlspecialchars((string)$key); ?>]" value="1">
                                                    <?php endif; ?>
                                                    <label class="form-check-label fw-semibold" for="profileField-<?php echo htmlspecialchars((string)$key, ENT_QUOTES); ?>"><?php echo htmlspecialchars((string)($field['label'] ?? $key)); ?></label>
                                                </div>
                                                <div class="text-muted small mb-2"><?php echo htmlspecialchars((string)($field['description'] ?? '')); ?></div>
                                                <label class="form-check form-switch mb-0">
                                                    <input type="checkbox" class="form-check-input" name="required_profile_fields[<?php echo htmlspecialchars((string)$key); ?>]" value="1" <?php echo in_array($key, $requiredProfileFields, true) ? 'checked' : ''; ?> <?php echo $isLockedRequired ? 'checked disabled' : ''; ?>>
                                                    <?php if ($isLockedRequired): ?>
                                                        <input type="hidden" name="required_profile_fields[<?php echo htmlspecialchars((string)$key); ?>]" value="1">
                                                    <?php endif; ?>
                                                    <span class="form-check-label">Pflichtfeld</span>
                                                </label>
                                                <?php if (!empty($field['recommended'])): ?>
                                                    <span class="badge bg-green-lt text-green mt-2">Empfohlen</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Zusätzliche Eingabefelder</h3>
                        </div>
                        <div class="card-body">
                            <p class="text-secondary small">Definiere projektspezifische Profilfelder. Der Schlüssel wird als User-Meta-Key gespeichert; ohne Präfix ergänzt 365CMS automatisch <code>custom_</code>.</p>
                            <?php $customRows = array_values(array_merge($customProfileFields, array_fill(0, max(1, 5 - count($customProfileFields)), []))); ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($customRows as $index => $customField): ?>
                                    <?php
                                    $customKey = (string)($customField['key'] ?? '');
                                    $customSelected = $customKey !== '' && in_array($customKey, $selectedProfileFields, true);
                                    ?>
                                    <div class="border rounded p-3">
                                        <div class="row g-3 align-items-end">
                                            <div class="col-md-3">
                                                <label class="form-label" for="customProfileLabel<?php echo (int)$index; ?>">Label</label>
                                                <input class="form-control" id="customProfileLabel<?php echo (int)$index; ?>" name="custom_profile_fields[<?php echo (int)$index; ?>][label]" value="<?php echo htmlspecialchars((string)($customField['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="z. B. Kundennummer">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label" for="customProfileKey<?php echo (int)$index; ?>">Schlüssel</label>
                                                <input class="form-control" id="customProfileKey<?php echo (int)$index; ?>" name="custom_profile_fields[<?php echo (int)$index; ?>][key]" value="<?php echo htmlspecialchars($customKey, ENT_QUOTES, 'UTF-8'); ?>" placeholder="custom_kundennummer" pattern="[a-zA-Z0-9_]+">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label" for="customProfileType<?php echo (int)$index; ?>">Typ</label>
                                                <select class="form-select" id="customProfileType<?php echo (int)$index; ?>" name="custom_profile_fields[<?php echo (int)$index; ?>][type]">
                                                    <?php foreach (['text' => 'Text', 'textarea' => 'Mehrzeilig', 'url' => 'URL', 'date' => 'Datum'] as $type => $label): ?>
                                                        <option value="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($customField['type'] ?? 'text') === $type) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-check mb-2">
                                                    <input type="checkbox" class="form-check-input" name="custom_profile_fields[<?php echo (int)$index; ?>][enabled]" value="1" <?php echo ($customSelected || !empty($customField['enabled'])) ? 'checked' : ''; ?>>
                                                    <span class="form-check-label">Anzeigen</span>
                                                </label>
                                                <label class="form-check mb-0">
                                                    <input type="checkbox" class="form-check-input" name="custom_profile_fields[<?php echo (int)$index; ?>][required]" value="1" <?php echo !empty($customField['required']) ? 'checked' : ''; ?>>
                                                    <span class="form-check-label">Pflicht</span>
                                                </label>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label" for="customProfileDescription<?php echo (int)$index; ?>">Hinweis</label>
                                                <input class="form-control" id="customProfileDescription<?php echo (int)$index; ?>" name="custom_profile_fields[<?php echo (int)$index; ?>][description]" value="<?php echo htmlspecialchars((string)($customField['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Optionaler Hilfetext">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-hint mt-3">Neue Custom-Felder bitte einmal speichern; danach kann „Anzeigen“ zuverlässig über den normalisierten Schlüssel gesteuert werden.</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">Navigation</h3>
                        </div>
                        <div class="card-body">
                            <label class="form-check form-switch">
                                <input type="checkbox" name="subscription_visible" class="form-check-input" value="1" <?php echo !empty($settings['subscription_visible']) ? 'checked' : ''; ?>>
                                <span class="form-check-label">Abo-Menüpunkt im Member-Bereich anzeigen</span>
                            </label>
                            <div class="form-hint mt-2">Steuert den zusätzlichen Menüeintrag „Abonnement“ im Mitgliederbereich.</div>
                        </div>
                    </div>

                    <div class="card sticky-top" style="top: 1rem;">
                        <div class="card-header">
                            <h3 class="card-title">Speichern</h3>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">Änderungen wirken sich direkt auf die Profilansicht und optionale Menüpunkte im Member-Bereich aus.</p>
                            <label class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" name="profile_fields_retrigger_onboarding" value="1">
                                <span class="form-check-label">Onboarding-Hinweis für unvollständige Profile aktivieren</span>
                            </label>
                            <div class="form-hint mb-3">Optionaler Re-Trigger: Aktiviert den vorhandenen Onboarding-/Profilabschluss-Hinweis im Member-Dashboard. Es werden keine E-Mails versendet und keine Benutzerprofile geändert.</div>
                            <button type="submit" class="btn btn-primary w-100">Profil-Felder speichern</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script <?= \CMS\Security::instance()->nonceAttr() ?>>
document.addEventListener('DOMContentLoaded', function () {
    var dataElement = document.getElementById('profileFieldCompatibilityData');
    var summaryElement = document.getElementById('profileCompatibilityLiveSummary');
    var risksElement = document.getElementById('profileCompatibilityFieldRisks');
    var checkboxes = document.querySelectorAll('[data-profile-field-checkbox]');

    if (!dataElement || !summaryElement || !risksElement || checkboxes.length === 0) {
        return;
    }

    var compatibility = {};
    try {
        compatibility = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        return;
    }

    var currentFields = Array.isArray(compatibility.current_fields) ? compatibility.current_fields : [];
    var currentLookup = currentFields.reduce(function (lookup, fieldKey) {
        lookup[String(fieldKey)] = true;
        return lookup;
    }, {});
    var fieldData = compatibility.fields || {};

    var clearElement = function (element) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    };

    var render = function () {
        var newlySelected = [];

        checkboxes.forEach(function (checkbox) {
            var fieldKey = checkbox.getAttribute('data-profile-field-key') || '';
            if (fieldKey !== '' && checkbox.checked && !currentLookup[fieldKey]) {
                newlySelected.push(fieldKey);
            }
        });

        clearElement(risksElement);

        if (newlySelected.length === 0) {
            summaryElement.className = 'alert alert-success mb-3';
            summaryElement.textContent = 'Keine neu ausgewählten Profilfelder gegenüber dem gespeicherten Stand. Bestehende Profilabschluss-Werte ändern sich dadurch voraussichtlich nicht.';

            var empty = document.createElement('div');
            empty.className = 'text-secondary small';
            empty.textContent = 'Wähle zusätzliche Felder aus, um mögliche neue Lücken vor dem Speichern sichtbar zu machen.';
            risksElement.appendChild(empty);
            return;
        }

        var totalFieldGaps = newlySelected.reduce(function (sum, fieldKey) {
            var data = fieldData[fieldKey] || {};
            return sum + Number(data.missing_count || 0);
        }, 0);

        summaryElement.className = 'alert alert-warning mb-3';
        summaryElement.textContent = newlySelected.length + ' neu ausgewählte(s) Feld(er) können bis zu ' + totalFieldGaps + ' zusätzliche Feldlücken erzeugen. Überschneidungen zwischen Benutzern sind möglich; die Detailzeilen zeigen begrenzte Beispielkonten.';

        newlySelected.forEach(function (fieldKey) {
            var data = fieldData[fieldKey] || {};
            var row = document.createElement('div');
            row.className = 'border rounded p-2';

            var title = document.createElement('div');
            title.className = 'd-flex justify-content-between gap-2 flex-wrap';
            title.innerHTML = '<span class="fw-semibold"></span><span class="badge bg-yellow-lt text-yellow"></span>';
            title.querySelector('.fw-semibold').textContent = String(data.label || fieldKey);
            title.querySelector('.badge').textContent = Number(data.missing_count || 0) + ' fehlende Werte';
            row.appendChild(title);

            var samples = Array.isArray(data.missing_samples) ? data.missing_samples : [];
            var sampleText = document.createElement('div');
            sampleText.className = 'text-secondary small mt-1';
            sampleText.textContent = samples.length > 0
                ? 'Beispiele: ' + samples.map(function (sample) { return String(sample.label || 'Benutzer'); }).join(', ')
                : 'Keine Beispielkonten im aktuellen Limit gefunden.';
            row.appendChild(sampleText);

            risksElement.appendChild(row);
        });
    };

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', render);
    });
    render();
});
</script>
