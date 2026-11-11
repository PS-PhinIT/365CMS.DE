<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS Installation - Admin-Layout</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%); min-height: 100vh; padding: 2rem; }
        .container { max-width: 800px; margin: 0 auto; }
        .card { background: white; border-radius: 16px; padding: 3rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .progress { display: flex; justify-content: center; align-items: center; margin-bottom: 2rem; gap: 0.5rem; }
        .step { width: 40px; aspect-ratio: 1; min-width: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; background: #e2e8f0; color: #64748b; }
        .step.active { background: #2563eb; color: white; }
        .step.completed { background: #10b981; color: white; }
        h1 { color: #1e293b; font-size: 2rem; margin-bottom: 0.5rem; }
        .subtitle { color: #64748b; margin-bottom: 2rem; }
        fieldset { border: 0; min-width: 0; }
        legend { font-weight: 600; margin-bottom: 0.75rem; color: #334155; }
        .profile-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); gap: 1rem; }
        .profile-card { display: flex; flex-direction: column; gap: 0.75rem; border: 2px solid #e2e8f0; border-radius: 12px; padding: 1rem; background: #f8fafc; cursor: pointer; transition: border-color 0.2s, background-color 0.2s, box-shadow 0.2s; }
        .profile-card:hover { border-color: #93c5fd; }
        .profile-card:has(input:checked) { border-color: #2563eb; background: #eff6ff; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .profile-card:has(input:focus-visible) { outline: 3px solid #1d4ed8; outline-offset: 2px; }
        .profile-head { display: flex; align-items: center; gap: 0.6rem; }
        .profile-head input { width: 1.1rem; height: 1.1rem; flex: 0 0 auto; margin: 0; accent-color: #2563eb; }
        .profile-title { font-weight: 700; color: #1e293b; font-size: 1.05rem; }
        .profile-chip { margin-left: auto; font-size: 0.72rem; font-weight: 600; color: #1d4ed8; background: #dbeafe; border-radius: 999px; padding: 0.1rem 0.55rem; }
        .profile-desc { font-size: 0.9rem; color: #64748b; line-height: 1.45; }
        /* Skizze der Admin-Sidebar (Farben wie die echte Sidebar) */
        .sketch { display: block; background: #e7e5e4; border: 1px solid #d6d3d1; border-radius: 10px; padding: 0.5rem; font-size: 0.78rem; color: #292524; }
        .sketch-section { display: block; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #78716c; padding: 0.55rem 0.45rem 0.2rem; }
        .sketch-section:first-child { padding-top: 0.15rem; }
        .sketch-row { display: flex; align-items: center; gap: 0.45rem; padding: 0.18rem 0.45rem; border-radius: 6px; line-height: 1.35; }
        .sketch-row--active { background: rgba(255, 255, 255, 0.75); font-weight: 600; }
        .sketch-dot { width: 0.7rem; height: 0.7rem; border-radius: 3px; background: #a8a29e; flex: 0 0 auto; }
        .sketch-label { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sketch-mark { flex: 0 0 auto; color: #78716c; }
        .sketch-mark--menu::before { content: '›'; font-weight: 700; font-size: 0.9rem; line-height: 1; }
        .sketch-mark--tiles { width: 9px; height: 9px; background:
            linear-gradient(currentColor 0 0) 0 0 / 4px 4px no-repeat,
            linear-gradient(currentColor 0 0) 100% 0 / 4px 4px no-repeat,
            linear-gradient(currentColor 0 0) 0 100% / 4px 4px no-repeat,
            linear-gradient(currentColor 0 0) 100% 100% / 4px 4px no-repeat; }
        .sketch-legend { display: flex; flex-wrap: wrap; gap: 0.4rem 1.25rem; margin-top: 0.75rem; font-size: 0.85rem; color: #64748b; }
        .sketch-legend span { display: inline-flex; align-items: center; gap: 0.4rem; }
        .help-text { font-size: 0.875rem; color: #64748b; margin-top: 1rem; line-height: 1.5; }
        .btn-group { display: flex; gap: 1rem; margin-top: 2rem; }
        .btn { flex: 1; padding: 1rem 2rem; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; text-decoration: none; text-align: center; transition: transform 0.2s; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; }
        .btn-secondary { background: #f1f5f9; color: #64748b; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="progress">
            <div class="step completed">✓</div>
            <div class="step completed">✓</div>
            <div class="step completed">✓</div>
            <div class="step active">4</div>
            <div class="step">5</div>
            <div class="step">6</div>
        </div>
        <h1>🧭 Admin-Layout wählen</h1>
        <p class="subtitle">So ist die Seitenleiste im Adminbereich aufgebaut. Seiten, Rechte und Adressen sind in beiden Layouts gleich – nur Gruppierung und Reihenfolge unterscheiden sich.</p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
            <input type="hidden" name="admin_layout" value="1">
            <fieldset>
                <legend>Layout der Admin-Seitenleiste</legend>
                <div class="profile-grid">
                    <?php foreach ($profiles as $profileKey => $profile): ?>
                        <?php $fieldId = 'admin_menu_profile_' . $profileKey; ?>
                        <label class="profile-card" for="<?php echo $escape($fieldId); ?>">
                            <span class="profile-head">
                                <input
                                    type="radio"
                                    id="<?php echo $escape($fieldId); ?>"
                                    name="admin_menu_profile"
                                    value="<?php echo $escape($profileKey); ?>"
                                    aria-labelledby="<?php echo $escape($fieldId . '_title'); ?>"
                                    aria-describedby="<?php echo $escape($fieldId . '_desc'); ?>"
                                    <?php echo $profileKey === $selectedProfile ? 'checked' : ''; ?>
                                >
                                <span class="profile-title" id="<?php echo $escape($fieldId . '_title'); ?>"><?php echo $escape($profile['label']); ?></span>
                                <?php if ($profileKey === 'classic'): ?>
                                    <span class="profile-chip">Standard</span>
                                <?php endif; ?>
                            </span>
                            <span class="profile-desc" id="<?php echo $escape($fieldId . '_desc'); ?>"><?php echo $escape($profile['description']); ?></span>
                            <span class="sketch" aria-hidden="true">
                                <?php $isFirstRow = true; ?>
                                <?php foreach ($profile['sections'] as $section): ?>
                                    <?php if ($section['label'] !== ''): ?>
                                        <span class="sketch-section"><?php echo $escape($section['label']); ?></span>
                                    <?php endif; ?>
                                    <?php foreach ($section['nodes'] as $node): ?>
                                        <span class="sketch-row<?php echo $isFirstRow ? ' sketch-row--active' : ''; ?>">
                                            <span class="sketch-dot"></span>
                                            <span class="sketch-label"><?php echo $escape($node['label']); ?></span>
                                            <?php if ($node['kind'] === 'group'): ?>
                                                <span class="sketch-mark sketch-mark--<?php echo $node['display'] === 'tiles' ? 'tiles' : 'menu'; ?>"></span>
                                            <?php endif; ?>
                                        </span>
                                        <?php $isFirstRow = false; ?>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="sketch-legend" aria-hidden="true">
                <span><span class="sketch-mark sketch-mark--menu"></span> Gruppe mit Untermenü</span>
                <span><span class="sketch-mark sketch-mark--tiles"></span> Kachelseite</span>
            </div>
            <p class="help-text">Die Vorschau zeigt die Hauptpunkte mit den im vorigen Schritt gewählten Core-Modulen. Das Layout lässt sich später unter <strong>Einstellungen → Admin-Menü</strong> wechseln oder im Menü-Editor frei anpassen.</p>

            <div class="btn-group">
                <a href="?step=3" class="btn btn-secondary">← Zurück</a>
                <button type="submit" class="btn btn-primary">Weiter →</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
