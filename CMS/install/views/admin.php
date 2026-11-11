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
    <title>CMS Installation - Administrator</title>
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
        input { width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem; transition: border-color 0.2s; }
        input:focus { outline: none; border-color: #2563eb; }
        .error-box { background: #fef2f2; border-left: 4px solid #ef4444; padding: 1rem; margin-bottom: 1.5rem; border-radius: 4px; color: #991b1b; }
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
            <div class="step completed">✓</div>
            <div class="step completed">✓</div>
            <div class="step active">5</div>
            <div class="step">6</div>
        </div>
        <h1>👤 Administrator anlegen</h1>
        <p class="subtitle">Erstellen Sie das erste Administratorkonto.</p>

        <?php if (!empty($errors)): ?>
        <div class="error-box"><?php echo implode('<br>', array_map($escape, $errors)); ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
            <input type="hidden" name="install" value="1">
            <div class="form-group">
                <label for="admin_username">Benutzername</label>
                <input type="text" id="admin_username" name="admin_username" value="" required autocomplete="off">
            </div>

            <div class="form-group">
                <label for="admin_email">E-Mail</label>
                <input type="email" id="admin_email" name="admin_email" value="<?php echo $escape($defaultEmail); ?>" required>
            </div>

            <div class="form-group">
                <label for="admin_password">Passwort</label>
                <input type="password" id="admin_password" name="admin_password" required autocomplete="new-password">
                <div class="help-text">Mindestens 12 Zeichen. Ein starkes Passwort spart später graue Haare.</div>
            </div>

            <div class="form-group">
                <label for="admin_password_confirm">Passwort bestätigen</label>
                <input type="password" id="admin_password_confirm" name="admin_password_confirm" required autocomplete="new-password">
            </div>

            <div class="btn-group">
                <a href="?step=4" class="btn btn-secondary">← Zurück</a>
                <button type="submit" class="btn btn-primary">Installation abschließen →</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
