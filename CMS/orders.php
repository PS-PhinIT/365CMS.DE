<?php
/**
 * Public Order / Checkout Page
 * 
 * @package 365CMS
 */

declare(strict_types=1);

// Load configuration
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    // If config doesn't exist, redirect to install
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
    }

    if (PHP_SAPI !== 'cli') {
        require_once __DIR__ . '/core/Contracts/CacheInterface.php';
        require_once __DIR__ . '/core/CacheManager.php';

        \CMS\CacheManager::instance()->sendResponseHeaders('private');
    }

    header('Location: install.php');
    exit;
}

// Load autoloader
require_once CORE_PATH . 'autoload.php';

use CMS\Auth;
use CMS\CacheManager;
use CMS\Database;
use CMS\Logger;
use CMS\Security;
use CMS\Services\CoreModuleService;
use CMS\SubscriptionManager;
use CMS\ThemeManager;

/** @return array<string, string> */
function cms_checkout_load_settings(Database $db, array $keys): array
{
    if ($keys === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $rows = $db->get_results(
        "SELECT option_name, option_value FROM {$db->getPrefix()}settings WHERE option_name IN ({$placeholders})",
        $keys
    ) ?: [];

    $settings = [];
    foreach ($rows as $row) {
        $settings[(string) ($row->option_name ?? '')] = (string) ($row->option_value ?? '');
    }

    return $settings;
}

function cms_checkout_bool_setting(array $settings, string $key, string $default = '0'): bool
{
    $value = strtolower(trim((string) ($settings[$key] ?? $default)));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Bestellbestätigung an Kunde und Kopie an ADMIN_EMAIL. Fehler brechen die Bestellung nicht ab.
 *
 * @param array<string, float> $totals
 */
function cms_checkout_send_confirmation(string $email, string $orderNumber, string $planName, string $billing, array $totals, string $paymentMethod, string $customerName): bool
{
    $siteName = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
    $cycleLabels = ['monthly' => 'monatlich', 'yearly' => 'jährlich', 'lifetime' => 'einmalig'];
    $paymentLabels = ['invoice' => 'Rechnung', 'stripe' => 'Kreditkarte (Stripe)', 'paypal' => 'PayPal'];
    $amount = number_format((float) ($totals['total_amount'] ?? 0), 2, ',', '.') . ' EUR';
    $lines = [
        'Guten Tag' . ($customerName !== '' ? ' ' . $customerName : '') . ',',
        '',
        'vielen Dank für Ihre Bestellung bei ' . $siteName . '.',
        '',
        'Bestellnummer: ' . $orderNumber,
        'Paket: ' . ($planName !== '' ? $planName : '-') . ' (' . ($cycleLabels[$billing] ?? $billing) . ')',
        'Gesamtbetrag: ' . $amount,
        'Zahlungsart: ' . ($paymentLabels[$paymentMethod] ?? $paymentMethod),
        '',
        'Wir melden uns, sobald die Bestellung bearbeitet ist.',
        '',
        $siteName,
    ];

    $sent = false;
    try {
        $mail = \CMS\Services\MailService::getInstance();
        $sent = $mail->sendPlain($email, 'Ihre Bestellung ' . $orderNumber, implode("\n", $lines));
        if (defined('ADMIN_EMAIL') && filter_var((string) ADMIN_EMAIL, FILTER_VALIDATE_EMAIL)) {
            $mail->sendPlain((string) ADMIN_EMAIL, 'Neue Bestellung ' . $orderNumber, implode("\n", array_merge(['Neue Bestellung über den Checkout:', '', 'Kunde: ' . $email], array_slice($lines, 4, 4))));
        }
    } catch (\Throwable $e) {
        Logger::instance()->withChannel('orders.checkout')->warning('Bestellbestätigung konnte nicht versendet werden.', [
            'order_number' => $orderNumber,
            'exception' => $e::class,
        ]);
    }

    return $sent;
}

/** @return list<string> */
function cms_checkout_available_payment_methods(string $setting): array
{
    return match (strtolower(trim($setting))) {
        'stripe' => ['stripe'],
        'paypal' => ['paypal'],
        'all' => ['invoice', 'stripe', 'paypal'],
        default => ['invoice'],
    };
}

function cms_checkout_normalize_payment_method(mixed $value, array $allowedMethods, string $fallback): string
{
    $method = is_string($value) ? strtolower(trim($value)) : '';

    return in_array($method, $allowedMethods, true) ? $method : $fallback;
}

function cms_checkout_payment_method_label(string $method): string
{
    return match ($method) {
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        default => 'Rechnung',
    };
}

/** @return array{net_amount:float,tax_amount:float,total_amount:float} */
function cms_checkout_calculate_totals(float $basePrice, float $taxRate, bool $taxIncluded): array
{
    $basePrice = max(0.0, round($basePrice, 2));
    $taxRate = max(0.0, min(100.0, $taxRate));

    if ($taxRate <= 0.0) {
        return [
            'net_amount' => $basePrice,
            'tax_amount' => 0.0,
            'total_amount' => $basePrice,
        ];
    }

    if ($taxIncluded) {
        $totalAmount = $basePrice;
        $netAmount = round($totalAmount / (1 + ($taxRate / 100)), 2);
        $taxAmount = max(0.0, round($totalAmount - $netAmount, 2));

        return [
            'net_amount' => $netAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
        ];
    }

    $netAmount = $basePrice;
    $taxAmount = round($netAmount * ($taxRate / 100), 2);
    $totalAmount = round($netAmount + $taxAmount, 2);

    return [
        'net_amount' => $netAmount,
        'tax_amount' => $taxAmount,
        'total_amount' => $totalAmount,
    ];
}

/** @return array{url:string,title:string} */
function cms_checkout_resolve_page_link(Database $db, int $pageId, string $fallbackTitle): array
{
    if ($pageId <= 0) {
        return ['url' => '', 'title' => $fallbackTitle];
    }

    try {
        $page = $db->get_row(
            "SELECT slug, title FROM {$db->getPrefix()}pages WHERE id = ? AND status = 'published' LIMIT 1",
            [$pageId]
        );
    } catch (\Throwable) {
        return ['url' => '', 'title' => $fallbackTitle];
    }

    $slug = trim((string) ($page->slug ?? ''));
    if ($slug === '') {
        return ['url' => '', 'title' => $fallbackTitle];
    }

    $title = trim((string) ($page->title ?? ''));

    return [
        'url' => '/' . ltrim($slug, '/'),
        'title' => $title !== '' ? $title : $fallbackTitle,
    ];
}

function cms_checkout_fallback_path(bool $isLoggedIn, bool $publicPricingEnabled): string
{
    if ($isLoggedIn) {
        return '/member/subscription';
    }

    return $publicPricingEnabled ? '/#pricing' : '/';
}

CacheManager::instance()->sendResponseHeaders('private');

// Get parameters
$planId = isset($_GET['plan']) ? (int)$_GET['plan'] : 0;
$billing = isset($_GET['billing']) ? $_GET['billing'] : 'monthly';
// Pläne haben nur Monats-/Jahrespreise. „lifetime“ wird ausschließlich im Admin vergeben,
// sonst ließe sich ein dauerhaftes Abo zum Monatspreis bestellen.
$billing = in_array($billing, ['monthly', 'yearly'], true) ? $billing : 'monthly';

// Services
$db = Database::instance();
$auth = Auth::instance();
$subManager = SubscriptionManager::instance();
$coreModuleService = CoreModuleService::getInstance();
$checkoutSettings = cms_checkout_load_settings($db, [
    'payment_methods',
    'tax_rate',
    'tax_included',
    'terms_page_id',
    'cancellation_page_id',
]);
$subscriptionsEnabled = $coreModuleService->isModuleEnabled('subscriptions');
$orderingEnabled = $coreModuleService->isModuleEnabled('subscription_ordering');
$publicPricingEnabled = $coreModuleService->isModuleEnabled('subscription_public_pricing');
$paymentMethods = cms_checkout_available_payment_methods((string) ($checkoutSettings['payment_methods'] ?? 'invoice'));
$defaultPaymentMethod = $paymentMethods[0] ?? 'invoice';
$taxRate = max(0.0, min(100.0, (float) ($checkoutSettings['tax_rate'] ?? '19')));
$taxIncluded = cms_checkout_bool_setting($checkoutSettings, 'tax_included', '1');
$termsLink = cms_checkout_resolve_page_link($db, (int) ($checkoutSettings['terms_page_id'] ?? 0), 'AGB');
$cancellationLink = cms_checkout_resolve_page_link($db, (int) ($checkoutSettings['cancellation_page_id'] ?? 0), 'Widerruf');

if (!$subscriptionsEnabled || !$orderingEnabled) {
    header('Location: /');
    exit;
}

// Fetch Plan
$plan = $db->execute("SELECT * FROM {$db->getPrefix()}subscription_plans WHERE id = ? AND is_active = 1", [$planId])->fetch(\PDO::FETCH_ASSOC);

$user = $auth->isLoggedIn() ? $auth->currentUser() : null;
$checkoutFallbackPath = cms_checkout_fallback_path($user !== null, $publicPricingEnabled);

if (!$plan) {
    header('Location: ' . $checkoutFallbackPath);
    exit;
}

$planBasePrice = (float) (($billing === 'yearly') ? ($plan['price_yearly'] ?? 0) : ($plan['price_monthly'] ?? 0));
$pricingTotals = cms_checkout_calculate_totals($planBasePrice, $taxRate, $taxIncluded);
$selectedPaymentMethod = cms_checkout_normalize_payment_method($_POST['payment_method'] ?? $defaultPaymentMethod, $paymentMethods, $defaultPaymentMethod);
$countryOptions = ['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz'];
$selectedCountry = strtoupper(trim((string) ($_POST['country'] ?? 'DE')));
if (!isset($countryOptions[$selectedCountry])) {
    $selectedCountry = 'DE';
}

// Handle Form Submission
$error = '';
$success = false;
$orderId = 0;
$confirmationSent = false;
$clientIp = Security::getClientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::instance()->verifyToken($_POST['csrf_token'] ?? '', 'checkout_process')) {
        $error = 'Sicherheitsüberprüfung fehlgeschlagen. Bitte versuchen Sie es erneut.';
    } elseif (!Security::checkDbRateLimit($clientIp, 'checkout_order', 5, 3600)) {
        // Öffentlicher Checkout ohne Limit erlaubte beliebig viele Bestellungen (DB-Wachstum, Spam).
        $error = 'Zu viele Bestellversuche. Bitte versuchen Sie es in einer Stunde erneut.';
    } else {
        // Validate inputs
        $contactData = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'company' => trim($_POST['company'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'zip' => trim($_POST['zip'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'country' => $selectedCountry,
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
        ];

        if (empty($contactData['last_name']) || empty($contactData['email']) || empty($contactData['address'])) {
            $error = 'Bitte füllen Sie alle Pflichtfelder aus (*).';
        } elseif (!filter_var($contactData['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
        } elseif ($termsLink['url'] !== '' && empty($_POST['accept_terms'])) {
            $error = 'Bitte akzeptieren Sie die AGB, um die Bestellung abzuschließen.';
        } elseif (!in_array($selectedPaymentMethod, $paymentMethods, true)) {
            $error = 'Bitte wählen Sie eine gültige Zahlungsmethode.';
        } else {
            // Generate Order Number
            // Fetch format from settings or default
            $format = $db->get_var("SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = 'setting_order_number_format'") ?: 'BST{Y}{M}-{ID}';
            
            // We need a temporary ID or just use a random string and update later if ID is needed for format
            // Simple generation for now:
            $nextId = $db->get_var("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$db->getPrefix()}orders'");
            if (!$nextId) {
                // Fallback if permission denied
                $maxId = $db->get_var("SELECT MAX(id) FROM {$db->getPrefix()}orders");
                $nextId = ($maxId ? $maxId : 0) + 1;
            }

            $placeholders = [
                '{Y}' => date('Y'),
                '{M}' => date('m'),
                '{D}' => date('d'),
                '{ID}' => str_pad((string)$nextId, 4, '0', STR_PAD_LEFT),
                '{R}' => strtoupper(substr(uniqid(), -4))
            ];
            
            $orderNumber = str_replace(array_keys($placeholders), array_values($placeholders), $format);

            // Price calculation
            $totals = cms_checkout_calculate_totals($planBasePrice, $taxRate, $taxIncluded);
            $customerName = trim($contactData['first_name'] . ' ' . $contactData['last_name']);
            
            // Insert Order
            try {
                $orderId = $db->insert('orders', [
                    'order_number' => $orderNumber,
                    'user_id' => $user ? $user->id : null,
                    'plan_id' => $plan['id'],
                    'billing_cycle' => $billing,
                    'customer_name' => $customerName !== '' ? $customerName : null,
                    'customer_email' => $contactData['email'],
                    'amount' => $totals['net_amount'],
                    'tax_amount' => $totals['tax_amount'],
                    'total_amount' => $totals['total_amount'],
                    'currency' => 'EUR',
                    'status' => 'pending',
                    'forename' => $contactData['first_name'],
                    'lastname' => $contactData['last_name'],
                    'company' => $contactData['company'],
                    'email' => $contactData['email'],
                    'phone' => $contactData['phone'],
                    'street' => $contactData['address'],
                    'zip' => $contactData['zip'],
                    'city' => $contactData['city'],
                    'country' => $contactData['country'],
                    'contact_data' => json_encode($contactData),
                    'payment_method' => $selectedPaymentMethod,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                
                $success = (bool) $orderId;
                Security::recordDbRateLimitAttempt($clientIp, 'checkout_order');

                if ($success) {
                    $confirmationSent = cms_checkout_send_confirmation(
                        $contactData['email'],
                        (string) $orderNumber,
                        (string) ($plan['name'] ?? ''),
                        $billing,
                        $totals,
                        $selectedPaymentMethod,
                        $customerName
                    );
                }

            } catch (\Throwable $e) {
                Logger::instance()->withChannel('orders.checkout')->error('Bestellung konnte nicht erstellt werden.', [
                    'plan_id' => (int) ($plan['id'] ?? 0),
                    'billing_cycle' => $billing,
                    'exception' => $e::class,
                ]);

                $error = 'Die Bestellung konnte gerade nicht abgeschlossen werden. Bitte versuchen Sie es erneut.';
            }
        }
    }
}

// Render Page
ob_start();
?>

<div class="checkout-container" style="max-width: 1000px; margin: 40px auto; padding: 20px;">
    
    <?php if ($success): ?>
        <div class="checkout-success" style="background: white; padding: 40px; border-radius: 8px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <div style="font-size: 60px; color: #10b981; margin-bottom: 20px;">✓</div>
            <h1>Vielen Dank für Ihre Bestellung!</h1>
            <p class="lead">Ihre Bestellnummer lautet: <strong><?php echo htmlspecialchars($orderNumber); ?></strong></p>
            <?php if ($confirmationSent): ?>
            <p>Wir haben Ihnen eine Bestätigung an <strong><?php echo htmlspecialchars($contactData['email']); ?></strong> gesendet.</p>
            <?php else: ?>
            <p>Ihre Bestellung ist eingegangen. Die Bestätigung an <strong><?php echo htmlspecialchars($contactData['email']); ?></strong> konnte gerade nicht versendet werden – bitte notieren Sie Ihre Bestellnummer.</p>
            <?php endif; ?>
            
            <div class="order-actions" style="margin-top: 30px; display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="btn btn-secondary" data-cms-print><span aria-hidden="true">🖨️</span> Drucken / PDF</button>
            </div>
            
            <div style="margin-top: 40px;">
                <a href="/" class="btn btn-primary">Zurück zur Startseite</a>
            </div>
        </div>
    <?php else: ?>
    
    <h1 style="margin-bottom: 30px;">Bestellung abschließen</h1>
    
    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert" style="background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="checkout-grid" style="display: grid; grid-template-columns: 1fr 350px; gap: 40px;">
        
        <!-- Left: Form -->
        <div class="checkout-form-section">
            <div class="card" style="background: white; padding: 25px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px;">Rechnungsdaten</h3>
                
                <?php if (!$user): ?>
                    <div class="alert alert-info" style="background: #eff6ff; color: #1e40af; padding: 10px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9rem;">
                        <a href="/login?redirect=<?php echo urlencode((string) ($_SERVER['REQUEST_URI'] ?? '/orders.php')); ?>" style="color: inherit; font-weight: bold;">Melden Sie sich an</a>, um Ihre gespeicherten Daten zu verwenden.
                    </div>
                <?php endif; ?>

                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::instance()->generateToken('checkout_process'); ?>">
                    
                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px;">
                        <div class="form-group">
                            <label for="checkout-first-name" style="display: block; margin-bottom: 5px; font-weight: 500;">Vorname</label>
                            <input id="checkout-first-name" autocomplete="given-name" type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ($user->first_name ?? '')); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>
                        <div class="form-group">
                            <label for="checkout-last-name" style="display: block; margin-bottom: 5px; font-weight: 500;">Nachname *</label>
                            <input id="checkout-last-name" autocomplete="family-name" type="text" name="last_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ($user->last_name ?? '')); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="checkout-company" style="display: block; margin-bottom: 5px; font-weight: 500;">Firma</label>
                        <input id="checkout-company" autocomplete="organization" type="text" name="company" class="form-control" value="<?php echo htmlspecialchars($_POST['company'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="checkout-address" style="display: block; margin-bottom: 5px; font-weight: 500;">Adresse *</label>
                        <input id="checkout-address" autocomplete="street-address" type="text" name="address" class="form-control" required value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>

                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 15px;">
                        <div class="form-group">
                            <label for="checkout-zip" style="display: block; margin-bottom: 5px; font-weight: 500;">PLZ *</label>
                            <input id="checkout-zip" autocomplete="postal-code" type="text" name="zip" class="form-control" required value="<?php echo htmlspecialchars($_POST['zip'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>
                        <div class="form-group">
                            <label for="checkout-city" style="display: block; margin-bottom: 5px; font-weight: 500;">Stadt *</label>
                            <input id="checkout-city" autocomplete="address-level2" type="text" name="city" class="form-control" required value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="checkout-country" style="display: block; margin-bottom: 5px; font-weight: 500;">Land</label>
                        <select id="checkout-country" autocomplete="country" name="country" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            <?php foreach ($countryOptions as $countryCode => $countryLabel): ?>
                                <option value="<?php echo htmlspecialchars($countryCode); ?>" <?php echo $selectedCountry === $countryCode ? 'selected' : ''; ?>><?php echo htmlspecialchars($countryLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="checkout-email" style="display: block; margin-bottom: 5px; font-weight: 500;">E-Mail Adresse *</label>
                        <input id="checkout-email" autocomplete="email" type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ($user->email ?? '')); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="checkout-payment-method" style="display: block; margin-bottom: 5px; font-weight: 500;">Zahlungsmethode</label>
                        <?php if (count($paymentMethods) > 1): ?>
                            <select id="checkout-payment-method" name="payment_method" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                <?php foreach ($paymentMethods as $paymentMethod): ?>
                                    <option value="<?php echo htmlspecialchars($paymentMethod); ?>" <?php echo $selectedPaymentMethod === $paymentMethod ? 'selected' : ''; ?>><?php echo htmlspecialchars(cms_checkout_payment_method_label($paymentMethod)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($defaultPaymentMethod); ?>">
                            <div class="form-control" style="background: #f8fafc;"><?php echo htmlspecialchars(cms_checkout_payment_method_label($defaultPaymentMethod)); ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($termsLink['url'] !== ''): ?>
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: flex; gap: 10px; align-items: flex-start; font-size: 0.95rem;">
                                <input type="checkbox" name="accept_terms" value="1" required <?php echo !empty($_POST['accept_terms']) ? 'checked' : ''; ?> style="margin-top: 3px;">
                                <span>
                                    Ich akzeptiere die <a href="<?php echo htmlspecialchars($termsLink['url']); ?>" target="_blank" rel="noopener">&nbsp;<?php echo htmlspecialchars($termsLink['title']); ?>&nbsp;</a>.
                                </span>
                            </label>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 30px;">
                        <button type="submit" class="btn btn-primary" style="background: #2563eb; color: white; border: none; padding: 12px 24px; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; width: 100%;">Zahlungspflichtig bestellen</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right: Summary -->
        <div class="checkout-summary-section">
            <div class="card" style="background: #f8fafc; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; position: sticky; top: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 20px;">Bestellübersicht</h3>
                
                <div class="summary-item" style="margin-bottom: 15px;">
                    <strong style="display: block; color: #64748b; font-size: 0.9rem;">Paket</strong>
                    <div style="font-size: 1.1rem; font-weight: 600;"><?php echo htmlspecialchars($plan['name']); ?></div>
                </div>

                <div class="summary-item" style="margin-bottom: 15px;">
                    <strong style="display: block; color: #64748b; font-size: 0.9rem;">Abrechnungszeitraum</strong>
                    <div><?php echo $billing === 'yearly' ? 'Jährlich' : ($billing === 'lifetime' ? 'Lifetime' : 'Monatlich'); ?></div>
                </div>

                <div class="summary-item" style="margin-bottom: 15px;">
                    <strong style="display: block; color: #64748b; font-size: 0.9rem;">Zahlungsmethode</strong>
                    <div><?php echo htmlspecialchars(cms_checkout_payment_method_label($selectedPaymentMethod)); ?></div>
                </div>

                <hr style="border-color: #cbd5e1; margin: 20px 0;">

                <div class="total" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; color: #64748b;">
                    <span>Netto:</span>
                    <span><?php echo number_format($pricingTotals['net_amount'], 2, ',', '.'); ?> €</span>
                </div>

                <?php if ($pricingTotals['tax_amount'] > 0): ?>
                    <div class="total" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; color: #64748b;">
                        <span>MwSt. (<?php echo number_format($taxRate, 0, ',', '.'); ?> %):</span>
                        <span><?php echo number_format($pricingTotals['tax_amount'], 2, ',', '.'); ?> €</span>
                    </div>
                <?php endif; ?>

                <div class="total" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                    <span style="font-weight: 600;">Gesamtbetrag:</span>
                    <span style="font-size: 1.5rem; font-weight: bold; color: #2563eb;">
                        <?php echo number_format($pricingTotals['total_amount'], 2, ',', '.'); ?> €
                    </span>
                </div>
                <div style="text-align: right; color: #64748b; font-size: 0.85rem;">
                    <?php echo $taxIncluded ? 'inkl.' : 'zzgl.'; ?> MwSt.
                </div>

                <?php if ($termsLink['url'] !== '' || $cancellationLink['url'] !== ''): ?>
                    <hr style="border-color: #cbd5e1; margin: 20px 0;">
                    <div style="font-size: 0.9rem; color: #64748b; display: grid; gap: 6px;">
                        <?php if ($termsLink['url'] !== ''): ?>
                            <a href="<?php echo htmlspecialchars($termsLink['url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($termsLink['title']); ?></a>
                        <?php endif; ?>
                        <?php if ($cancellationLink['url'] !== ''): ?>
                            <a href="<?php echo htmlspecialchars($cancellationLink['url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($cancellationLink['title']); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();

// Inline-Handler (onclick) blockiert die CSP; Drucken-Button per Nonce-Skript verdrahten.
\CMS\Hooks::addAction('body_end', static function (): void {
    echo '<script ' . Security::instance()->nonceAttr() . '>document.querySelectorAll("[data-cms-print]").forEach(function(b){b.addEventListener("click",function(){window.print();});});</script>' . "\n";
}, 50);

// Render with Theme
ThemeManager::instance()->render('page', [
    'page' => [
        'title' => 'Kasse',
        'content' => $content
    ]
]);
?>