<?php
/**
 * Katalog Audit 4.2.0 (AP-08, Zahlungen): Bestellnummer aus der echten ID (DB-420-01) und Ortszeit der Abo-Spalten (DB-420-02), Suite `payments`.
 *
 * Der Gesamtkatalog ist auf 800 Mutanten begrenzt (Treiber); die übrigen Regeln dieses Audits prüft die Suite direkt (Abschnitte „Audit 4.2.0“).
 *
 * Gleiches Format wie `catalog.php`: Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * @package CMSv2\Core\Services\Payments
 */

declare(strict_types=1);

return [
    'audit420-order-number-id' => ['core/Services/Payments/PaymentService.php', "if (!str_contains(\$pattern, '{ID}')) {", 'if (false) {', 'payments', false, 'Eine Bestellnummer enthält immer die echte ID (ohne {ID} im Format wird -{ID} angehängt)', true, []],
    'audit420-end-date-local' => ['core/Services/Payments/PaymentService.php', '$end = self::localTime($periodEnd);', "\$end = gmdate('Y-m-d H:i:s', \$periodEnd);", 'payments', false, 'end_date/next_billing_date in Ortszeit wie SubscriptionManager (Vergleich mit NOW())', true, []],
];
