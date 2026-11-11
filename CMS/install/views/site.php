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
    <title>CMS Installation - Website</title>
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
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; font-weight: 600; margin-bottom: 0.5rem; color: #334155; }
        input[type="text"], input[type="email"], input[type="url"] { width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem; transition: border-color 0.2s; }
        input:focus { outline: none; border-color: #2563eb; }
        .checkbox-group { display: flex; align-items: center; gap: 0.5rem; }
        .module-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
        .module-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 1rem; background: #f8fafc; }
        .module-card label { display: flex; align-items: flex-start; gap: 0.75rem; margin: 0; cursor: pointer; }
        .module-card input[type="checkbox"] { width: 1.1rem; height: 1.1rem; margin-top: 0.15rem; flex: 0 0 auto; }
        .module-title { font-weight: 700; color: #1e293b; margin-bottom: 0.25rem; }
        .module-copy { font-size: 0.92rem; color: #64748b; line-height: 1.45; }
        .help-text { font-size: 0.875rem; color: #64748b; margin-top: 0.25rem; }
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
            <div class="step active">3</div>
            <div class="step">4</div>
            <div class="step">5</div>
            <div class="step">6</div>
        </div>
        <h1>🌐 Website konfigurieren</h1>
        <p class="subtitle">Grundlegende Informationen für Ihre neue Installation.</p>

        <?php if (!empty($dbCleaned['dropped'])): ?>
        <div class="help-text" style="background:#ecfdf5;border-left:4px solid #10b981;padding:1rem;border-radius:8px;margin-bottom:1.5rem;color:#065f46;">
            Die Datenbank wurde für die Neuinstallation bereits bereinigt. Gelöschte Tabellen: <?php echo $escape(implode(', ', $dbCleaned['dropped'])); ?>
        </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
            <input type="hidden" name="site_config" value="1">
            <div class="form-group">
                <label for="site_name">Website-Name</label>
                <input type="text" id="site_name" name="site_name" value="<?php echo $escape($defaultValues['site_name'] ?? 'IT Expert Network'); ?>" required>
            </div>

            <div class="form-group">
                <label for="site_url">Website-URL</label>
                <input type="url" id="site_url" name="site_url" value="<?php echo $escape($defaultValues['site_url'] ?? ''); ?>" required>
                <div class="help-text">Bitte ohne abschließenden Slash eingeben.</div>
            </div>

            <div class="form-group">
                <label for="admin_email">Administrator E-Mail</label>
                <input type="email" id="admin_email" name="admin_email" value="<?php echo $escape($defaultValues['admin_email'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="debug_mode" name="debug_mode" <?php echo !empty($defaultValues['debug_mode']) ? 'checked' : ''; ?>>
                    <label for="debug_mode" style="margin: 0;">Debug-Modus aktivieren</label>
                </div>
                <div class="help-text">Nur für lokale Entwicklung oder Fehlersuche aktivieren.</div>
            </div>

            <div class="form-group">
                <label>Core-Module aktivieren</label>
                <div class="help-text" style="margin-bottom: 0.75rem;">Nur die hier ausgewählten Core-Module werden in der neuen Installation direkt aktiviert. Nicht ausgewählte Module bleiben deaktiviert, bis sie später unter <strong>System → Module</strong> eingeschaltet werden.</div>
                <div class="module-grid">
                    <?php foreach (($availableCoreModules ?? []) as $module): ?>
                        <?php
                        $moduleSlug = (string) ($module['slug'] ?? '');
                        if ($moduleSlug === '') {
                            continue;
                        }

                        $moduleChecked = !empty($defaultValues['core_modules'][$moduleSlug]);
                        ?>
                        <div class="module-card">
                            <label for="core_module_<?php echo $escape($moduleSlug); ?>">
                                <input
                                    type="checkbox"
                                    id="core_module_<?php echo $escape($moduleSlug); ?>"
                                    name="core_modules[<?php echo $escape($moduleSlug); ?>]"
                                    value="1"
                                    <?php echo $moduleChecked ? 'checked' : ''; ?>
                                >
                                <span>
                                    <span class="module-title"><?php echo $escape((string) ($module['label'] ?? $moduleSlug)); ?></span>
                                    <span class="module-copy"><?php echo $escape((string) ($module['description'] ?? '')); ?></span>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="btn-group">
                <a href="?step=2" class="btn btn-secondary">← Zurück</a>
                <button type="submit" class="btn btn-primary">Weiter →</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
