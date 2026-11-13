<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$emptyStateColspan = max(1, (int) ($emptyStateColspan ?? 1));
$emptyStateMessage = trim((string) ($emptyStateMessage ?? 'Keine Einträge vorhanden.'));
$emptyStateSubtitle = trim((string) ($emptyStateSubtitle ?? ''));
$emptyStateIcon = (string) ($emptyStateIcon ?? 'default');

if (preg_match('/^(?:Noch\\s+)?Keine\\s+(.+?)\\s+(?:vorhanden|angelegt|registriert|konfiguriert|protokolliert|gefunden|verfügbar)\\.?$/iu', $emptyStateMessage, $matches) === 1) {
    $entity = trim((string) ($matches[1] ?? 'Einträge'));
    if ($entity !== '') {
        $emptyStateMessage = 'Keine ' . $entity . ' vorhanden.';
    }
}

if ($emptyStateSubtitle === '') {
    $emptyStateSubtitle = 'Sobald neue Einträge verfügbar sind, erscheinen sie hier automatisch.';
}

$iconMap = [
    'comments' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 20l1.3 -3.9c-2.324 -3.437 -1.426 -7.872 2.1 -10.374c3.526 -2.501 8.59 -2.296 11.845 .48c3.255 2.777 3.695 7.266 1.029 10.501c-2.666 3.235 -7.615 4.215 -11.574 2.293l-4.7 1"/>',
    'table' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 10h18"/><path d="M10 3v18"/><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2z"/>',
    'hub' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/><path d="M5 5h4v4h-4z"/><path d="M15 5h4v4h-4z"/><path d="M5 15h4v4h-4z"/><path d="M15 15h4v4h-4z"/>',
    'plugin' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h10v10h-10z"/><path d="M9 3v4"/><path d="M15 3v4"/><path d="M3 9h4"/><path d="M3 15h4"/><path d="M17 9h4"/><path d="M17 15h4"/>',
    'shield' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4v5c0 5 -3.5 9 -8 9s-8 -4 -8 -9v-5l8 -4"/><path d="M9 12l2 2l4 -4"/>',
    'file-text' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M9 9l1 0"/><path d="M9 13l6 0"/><path d="M9 17l6 0"/>',
    'users' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/>',
    'default' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 6l11 0"/><path d="M9 12l11 0"/><path d="M9 18l11 0"/><path d="M5 6l0 .01"/><path d="M5 12l0 .01"/><path d="M5 18l0 .01"/>',
];
$iconPath = $iconMap[$emptyStateIcon] ?? $iconMap['default'];
?>
<tr>
    <td colspan="<?php echo $emptyStateColspan; ?>" class="py-4">
        <div class="admin-empty-table-state empty text-center">
            <div class="empty-img text-secondary mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="32" height="32" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <?php echo $iconPath; ?>
                </svg>
            </div>
            <p class="empty-title mb-1"><?php echo htmlspecialchars($emptyStateMessage, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($emptyStateSubtitle !== ''): ?>
                <p class="empty-subtitle text-secondary mb-0"><?php echo htmlspecialchars($emptyStateSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
        </div>
    </td>
</tr>
