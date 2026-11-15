<?php
/**
 * Einstellungen der Zahlungsanbieter (4.1.0, Bereich 5a), Gruppe `payments` im `SettingsService`.
 *
 * - Getrennte Schlüssel für Test und Live; welcher gilt, entscheidet „Testmodus“.
 * - Alle Schlüssel und Webhook-Geheimnisse werden verschlüsselt gespeichert; leere Formularfelder behalten den
 *   gespeicherten Wert. Präfixe werden geprüft (`sk_test_`/`rk_test_` im Test, `whsec_`, `test_`/`live_` bei Mollie),
 *   damit ein Live-Schlüssel nicht versehentlich im Testmodus landet.
 * - `return_key`: zufälliger Schlüssel für signierte Rücksprung-Links, wird beim ersten Bedarf erzeugt.
 *
 * @package CMSv2\Core\Services\Payments
 */

declare(strict_types=1);

namespace CMS\Services\Payments;

if (!defined('ABSPATH')) {
    exit;
}

final class PaymentSettings
{
    public const GROUP = 'payments';
    public const PROVIDERS = ['stripe', 'mollie'];
    public const PLAIN_KEYS = ['stripe_enabled', 'mollie_enabled', 'test_mode', 'recurring'];
    public const SECRET_KEYS = [
        'stripe_secret_test' => '/^(sk|rk)_test_[A-Za-z0-9]{10,}$/',
        'stripe_secret_live' => '/^(sk|rk)_live_[A-Za-z0-9]{10,}$/',
        'stripe_webhook_secret_test' => '/^whsec_[A-Za-z0-9+\/=]{10,}$/',
        'stripe_webhook_secret_live' => '/^whsec_[A-Za-z0-9+\/=]{10,}$/',
        'mollie_key_test' => '/^test_[A-Za-z0-9]{20,}$/',
        'mollie_key_live' => '/^live_[A-Za-z0-9]{20,}$/',
    ];
    private const SECRET_LABELS = [
        'stripe_secret_test' => 'Stripe Secret Key (Test)',
        'stripe_secret_live' => 'Stripe Secret Key (Live)',
        'stripe_webhook_secret_test' => 'Stripe Webhook-Geheimnis (Test)',
        'stripe_webhook_secret_live' => 'Stripe Webhook-Geheimnis (Live)',
        'mollie_key_test' => 'Mollie API-Key (Test)',
        'mollie_key_live' => 'Mollie API-Key (Live)',
    ];

    /** @param array<string, string> $values */
    public function __construct(private array $values)
    {
    }

    public static function load(): self
    {
        $settings = \CMS\Services\SettingsService::getInstance();
        $values = [];
        foreach (array_merge(self::PLAIN_KEYS, array_keys(self::SECRET_KEYS), ['return_key']) as $key) {
            $values[$key] = (string) $settings->get(self::GROUP, $key, '');
        }

        return new self($values);
    }

    public function testMode(): bool
    {
        return ($this->values['test_mode'] ?? '1') !== '0';
    }

    public function recurring(): bool
    {
        return ($this->values['recurring'] ?? '0') === '1';
    }

    public function enabled(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true) && ($this->values[$provider . '_enabled'] ?? '0') === '1';
    }

    public function stripeSecret(): string
    {
        return (string) ($this->values['stripe_secret_' . $this->mode()] ?? '');
    }

    public function stripeWebhookSecret(): string
    {
        return (string) ($this->values['stripe_webhook_secret_' . $this->mode()] ?? '');
    }

    public function mollieKey(): string
    {
        return (string) ($this->values['mollie_key_' . $this->mode()] ?? '');
    }

    public function mode(): string
    {
        return $this->testMode() ? 'test' : 'live';
    }

    public function has(string $key): bool
    {
        return trim((string) ($this->values[$key] ?? '')) !== '';
    }

    /**
     * Schlüssel für signierte Rücksprung-Links; fehlt er, wird er erzeugt und gespeichert.
     */
    public function returnKey(): string
    {
        $key = (string) ($this->values['return_key'] ?? '');
        if (strlen($key) < 32) {
            $key = bin2hex(random_bytes(32));
            $this->values['return_key'] = $key;
            try {
                \CMS\Services\SettingsService::getInstance()->set(self::GROUP, 'return_key', $key, true);
            } catch (\Throwable) {
                // Ohne Speicher (Tests) gilt der Schlüssel nur für diese Anfrage.
            }
        }

        return $key;
    }

    /**
     * Prüft und speichert das Formular. Leere Geheimnis-Felder behalten den gespeicherten Wert.
     *
     * @param array<string, mixed> $input
     * @return array{success: bool, error?: string, details?: list<string>, message?: string}
     */
    public static function save(array $input, ?self $current = null, ?\Closure $writer = null): array
    {
        $current ??= self::load();
        $writer ??= static function (string $key, string $value, bool $secret): void {
            \CMS\Services\SettingsService::getInstance()->set(self::GROUP, $key, $value, $secret);
        };
        $plain = [];
        foreach (self::PLAIN_KEYS as $key) {
            $plain[$key] = !empty($input[$key]) ? '1' : '0';
        }
        $errors = [];
        $secrets = [];
        foreach (self::SECRET_KEYS as $key => $pattern) {
            $value = trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : '');
            if ($value === '') {
                continue;
            }
            if (preg_match($pattern, $value) !== 1) {
                $errors[] = self::SECRET_LABELS[$key] . ': Format passt nicht (Test- und Live-Schlüssel nicht vertauschen).';
                continue;
            }
            $secrets[$key] = $value;
        }
        $merged = new self(array_merge($current->values, $plain, $secrets));
        foreach (self::PROVIDERS as $provider) {
            if ($merged->enabled($provider)) {
                foreach ($merged->missing($provider) as $label) {
                    $errors[] = $label . ' fehlt (' . ($merged->testMode() ? 'Testmodus' : 'Live-Modus') . ').';
                }
            }
        }
        if ($errors !== []) {
            return ['success' => false, 'error' => 'Zahlungseinstellungen nicht gespeichert.', 'details' => $errors];
        }
        foreach ($plain as $key => $value) {
            $writer($key, $value, false);
        }
        foreach ($secrets as $key => $value) {
            $writer($key, $value, true);
        }

        return ['success' => true, 'message' => 'Zahlungseinstellungen gespeichert.'];
    }

    /**
     * Fehlende Angaben eines Anbieters im aktuellen Modus.
     *
     * @return list<string>
     */
    public function missing(string $provider): array
    {
        $mode = $this->mode();
        $required = match ($provider) {
            'stripe' => ['stripe_secret_' . $mode, 'stripe_webhook_secret_' . $mode],
            'mollie' => ['mollie_key_' . $mode],
            default => [],
        };

        return array_values(array_map(
            static fn (string $key): string => self::SECRET_LABELS[$key],
            array_filter($required, fn (string $key): bool => !$this->has($key))
        ));
    }

    /**
     * Status für die Admin-Seite: welche Felder gesetzt sind (nie die Werte).
     *
     * @return array<string, bool>
     */
    public function secretStatus(): array
    {
        $status = [];
        foreach (array_keys(self::SECRET_KEYS) as $key) {
            $status[$key] = $this->has($key);
        }

        return $status;
    }

    /**
     * @return array<string, bool>
     */
    public function flags(): array
    {
        return [
            'stripe_enabled' => $this->enabled('stripe'),
            'mollie_enabled' => $this->enabled('mollie'),
            'test_mode' => $this->testMode(),
            'recurring' => $this->recurring(),
        ];
    }
}
