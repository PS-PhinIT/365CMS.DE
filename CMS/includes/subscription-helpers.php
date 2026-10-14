<?php
/**
 * Subscription Helper Functions
 * 
 * Hilfs-Funktionen für Plugin-Integration mit Subscription-System
 * 
 * @package CMSv2\Includes
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prüft, ob Abo-/Limit-Durchsetzung systemweit aktiv ist.
 *
 * Wenn das Core-Modul `subscriptions` oder `subscription_limits` deaktiviert ist,
 * dürfen Plugin-Helfer keine Upgrade-/Limit-Warnungen ausgeben und keine Limits
 * erzwingen.
 */
function subscription_limit_enforcement_active(): bool
{
    try {
        if (class_exists('\CMS\Services\CoreModuleService')) {
            $coreModules = \CMS\Services\CoreModuleService::getInstance();
            if (!$coreModules->isModuleEnabled('subscriptions')) {
                return false;
            }

            if (!$coreModules->isModuleEnabled('subscription_limits')) {
                return false;
            }
        }

        if (class_exists('\CMS\SubscriptionManager')) {
            return \CMS\SubscriptionManager::instance()->isLimitEnforcementEnabled();
        }
    } catch (\Throwable $e) {
        return false;
    }

    return false;
}

/**
 * Prüft ob aktueller Benutzer Zugriff auf Plugin hat (für Erstellen/Bearbeiten).
 *
 * Wichtig: Öffentliche ANSICHTEN (Archive, Detail-Seiten) sind für alle
 * zugänglich – auch Gäste. Diese Funktion nur für Erstell-/Bearbeitungs-
 * Operationen verwenden, NICHT für Archive/Single-Page-Gates.
 *
 * @param string $pluginSlug Plugin-Slug (z.B. 'cms-experts')
 * @return bool
 */
function user_can_access_plugin(string $pluginSlug): bool
{
    // Gäste (nicht eingeloggt) dürfen grundsätzlich ansehen.
    // Abo-Prüfung gilt nur für eingeloggte Nutzer bei Erstell-Aktionen.
    if (!is_logged_in()) {
        return true;
    }
    
    $user = current_user();
    if (!$user) {
        return true;
    }
    
    // Admins haben immer Zugriff
    if (is_admin()) {
        return true;
    }
    
    $subscriptionManager = CMS\SubscriptionManager::instance();
    return $subscriptionManager->canAccessPlugin($user->id, $pluginSlug);
}

/**
 * Prüft ob Benutzer noch Ressourcen erstellen darf
 *
 * @param string $resourceType Ressource-Typ (z.B. 'experts', 'companies')
 * @param int|null $userId User ID (null = aktueller Benutzer)
 * @return bool
 */
function user_can_create_resource(string $resourceType, ?int $userId = null): bool
{
    if (!subscription_limit_enforcement_active()) {
        return true;
    }

    if ($userId === null) {
        $user = current_user();
        if (!$user) {
            return false;
        }
        $userId = $user->id;
    }
    
    // Admins haben immer Zugriff
    if (is_admin()) {
        return true;
    }
    
    $subscriptionManager = CMS\SubscriptionManager::instance();
    return $subscriptionManager->checkLimit($userId, $resourceType);
}

/**
 * Holt aktuelles Limit für Ressource
 *
 * @param string $resourceType Ressource-Typ
 * @param int|null $userId User ID
 * @return int -1 = unbegrenzt, 0 = deaktiviert, >0 = Limit
 */
function get_user_resource_limit(string $resourceType, ?int $userId = null): int
{
    if (!subscription_limit_enforcement_active()) {
        return -1;
    }

    if ($userId === null) {
        $user = current_user();
        if (!$user) {
            return 0;
        }
        $userId = $user->id;
    }
    
    $subscriptionManager = CMS\SubscriptionManager::instance();
    $subscription = $subscriptionManager->getUserSubscription($userId);
    
    if (!$subscription) {
        return 0;
    }
    
    $limitField = 'limit_' . str_replace('-', '_', $resourceType);
    return (int)($subscription->{$limitField} ?? 0);
}

/**
 * Holt aktuelle Nutzung einer Ressource
 *
 * @param string $resourceType Ressource-Typ
 * @param int|null $userId User ID
 * @return int
 */
function get_user_resource_usage(string $resourceType, ?int $userId = null): int
{
    if (!subscription_limit_enforcement_active()) {
        return 0;
    }

    if ($userId === null) {
        $user = current_user();
        if (!$user) {
            return 0;
        }
        $userId = $user->id;
    }
    
    $subscriptionManager = CMS\SubscriptionManager::instance();
    return $subscriptionManager->getCurrentUsage($userId, $resourceType);
}

/**
 * Zeigt Limit-Warnung an
 *
 * @param string $resourceType Ressource-Typ
 * @param string $resourceLabel Anzeige-Name (z.B. 'Experten')
 * @return void
 */
function display_resource_limit_warning(string $resourceType, string $resourceLabel): void
{
    if (!subscription_limit_enforcement_active()) {
        return;
    }

    $limit = get_user_resource_limit($resourceType);
    $usage = get_user_resource_usage($resourceType);
    
    if ($limit === -1) {
        return; // Unbegrenzt
    }
    
    if ($limit === 0) {
        echo '<div class="alert alert-danger" style="margin: 1rem 0; padding: 1rem; background: #fee2e2; color: #991b1b; border-radius: 8px;">';
        echo '<strong>⚠️ Feature nicht verfügbar:</strong> ' . htmlspecialchars($resourceLabel) . ' sind in Ihrem Abo-Paket nicht enthalten. ';
        echo '<a href="' . SITE_URL . '/member/subscription" style="color: #991b1b; text-decoration: underline;">Jetzt upgraden</a>';
        echo '</div>';
        return;
    }
    
    $remaining = $limit - $usage;
    $percentage = ($usage / $limit) * 100;
    
    if ($remaining <= 0) {
        echo '<div class="alert alert-danger" style="margin: 1rem 0; padding: 1rem; background: #fee2e2; color: #991b1b; border-radius: 8px;">';
        echo '<strong>🚫 Limit erreicht:</strong> Sie haben Ihr Limit von ' . $limit . ' ' . htmlspecialchars($resourceLabel) . ' erreicht. ';
        echo '<a href="' . SITE_URL . '/member/subscription" style="color: #991b1b; text-decoration: underline;">Abo upgraden</a>';
        echo '</div>';
    } elseif ($percentage >= 80) {
        echo '<div class="alert alert-warning" style="margin: 1rem 0; padding: 1rem; background: #fef3c7; color: #92400e; border-radius: 8px;">';
        echo '<strong>⚠️ Warnung:</strong> Sie haben ' . $usage . ' von ' . $limit . ' ' . htmlspecialchars($resourceLabel) . ' verwendet (' . round($percentage) . '%). ';
        echo 'Nur noch ' . $remaining . ' verfügbar.';
        echo '</div>';
    }
}

/**
 * Holt Abo-Informationen des aktuellen Benutzers
 *
 * @return object|null
 */
function get_current_subscription(): ?object
{
    $user = current_user();
    if (!$user) {
        return null;
    }
    
    $subscriptionManager = CMS\SubscriptionManager::instance();
    return $subscriptionManager->getUserSubscription($user->id);
}

/**
 * Prüft ob Benutzer ein Feature nutzen darf
 *
 * @param string $feature Feature-Name (z.B. 'analytics', 'api_access')
 * @return bool
 */
function user_has_feature(string $feature): bool
{
    if (is_admin()) {
        return true;
    }
    
    $subscription = get_current_subscription();
    if (!$subscription) {
        return false;
    }
    
    $featureField = 'feature_' . str_replace('-', '_', $feature);
    return !empty($subscription->{$featureField});
}

/**
 * Aktualisiert Ressourcen-Nutzung
 *
 * @param string $resourceType Ressource-Typ
 * @param int $count Neuer Zähler-Stand
 * @param int|null $userId User ID
 * @return bool
 */
function update_resource_usage(string $resourceType, int $count, ?int $userId = null): bool
{
    if ($userId === null) {
        $user = current_user();
        if (!$user) {
            return false;
        }
        $userId = $user->id;
    }
    
    $subscriptionManager = CMS\SubscriptionManager::instance();
    return $subscriptionManager->updateUsage($userId, $resourceType, $count);
}

/**
 * Zeigt Upgrade-Notice an
 *
 * @param string $message Custom Nachricht
 * @return void
 */
function display_upgrade_notice(string $message = ''): void
{
    if (empty($message)) {
        $message = 'Upgraden Sie Ihr Abo, um dieses Feature freizuschalten!';
    }
    
    echo '<div class="upgrade-notice" style="margin: 2rem 0; padding: 2rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px; text-align: center;">';
    echo '<h3 style="margin: 0 0 1rem; font-size: 1.5rem;">🚀 Upgrade erforderlich</h3>';
    echo '<p style="margin: 0 0 1.5rem; font-size: 1.1rem;">' . htmlspecialchars($message) . '</p>';
    echo '<a href="' . SITE_URL . '/member/subscription" class="btn btn-light" style="background: white; color: #667eea; padding: 0.75rem 2rem; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-block;">';
    echo 'Jetzt upgraden →';
    echo '</a>';
    echo '</div>';
}
