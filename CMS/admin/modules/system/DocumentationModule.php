<?php
declare(strict_types=1);

/**
 * Dokumentations-Modul
 *
 * Orchestriert Dokumentkatalog und Rendering für die Liveansicht des lokalen
 * Repository-Verzeichnisses `/DOC` im Admin-Bereich `/admin/documentation`.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Logger;

require_once __DIR__ . '/DocumentationCatalog.php';
require_once __DIR__ . '/DocumentationRenderer.php';

final class DocumentationViewData
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}

final class DocumentationModule
{
    private const MAX_SELECTED_DOC_LENGTH = 240;
    private const MAX_UI_ERROR_LENGTH = 180;

    private readonly string $docsRoot;
    private readonly string $repoRoot;
    private readonly DocumentationCatalog $catalog;
    private readonly DocumentationRenderer $renderer;
    private readonly Logger $logger;

    public function __construct()
    {
        $resolvedRoots = $this->resolveDocumentationRoots();
        $this->repoRoot = $resolvedRoots['repo_root'];
        $this->docsRoot = $resolvedRoots['docs_root'];
        $this->logger = Logger::instance()->withChannel('admin.documentation');

        $this->catalog = new DocumentationCatalog(
            $this->docsRoot,
            ''
        );
        $this->renderer = new DocumentationRenderer([$this->catalog, 'resolveLink']);
    }

    public function getData(?string $selectedDoc = null): DocumentationViewData
    {
        $viewValidation = $this->validateViewRequest();
        if ($viewValidation instanceof DocumentationViewData) {
            return $viewValidation;
        }

        try {
            $catalogSelection = $this->loadCatalogSelection($selectedDoc);

            return $this->buildAvailableData(
                $catalogSelection['catalog'],
                $catalogSelection['selected_document']
            );
        } catch (\Throwable $e) {
            $this->logThrowableWarning('Dokumentationsdaten konnten nicht geladen werden.', $e);

            return $this->errorData('Die Dokumentation konnte gerade nicht geladen werden. Bitte Logs prüfen.');
        }
    }

    // ── Doku-Sync ─────────────────────────────────────────────────────────────

    /**
     * Konfiguration (optional in config/app.php):
     * - CMS_DOCS_SYNC_REMOTE / CMS_DOCS_SYNC_BRANCH  (Git-Modus, Standard origin/main)
     * - CMS_DOCS_SYNC_ZIP_URL                          (ZIP-Modus, Standard GitHub-Archiv von main)
     * - CMS_DOCS_SYNC_BUNDLE_SHA256 / CMS_DOCS_SYNC_BUNDLE_FILES  (Pflicht für den ZIP-Modus: freigegebenes Bundle)
     */
    private function createSyncService(): DocumentationSyncService
    {
        require_once __DIR__ . '/DocumentationSyncService.php';

        $constant = static fn(string $name, string $default): string => defined($name) ? trim((string) constant($name)) : $default;

        return new DocumentationSyncService(
            $this->repoRoot,
            $this->docsRoot,
            $constant('CMS_DOCS_SYNC_ZIP_URL', 'https://codeload.github.com/PS-PhinIT/365CMS.DE/zip/refs/heads/main'),
            strtolower($constant('CMS_DOCS_SYNC_BUNDLE_SHA256', '')),
            (int) $constant('CMS_DOCS_SYNC_BUNDLE_FILES', '0'),
            $constant('CMS_DOCS_SYNC_REMOTE', 'origin'),
            $constant('CMS_DOCS_SYNC_BRANCH', 'main')
        );
    }

    /**
     * @return array{can_sync: bool, mode: string, label: string, message: string}
     */
    public function getSyncStatus(): array
    {
        try {
            $capabilities = $this->createSyncService()->getSyncCapabilities()->toArray();
        } catch (\Throwable $e) {
            $this->logThrowableWarning('Doku-Sync-Status konnte nicht ermittelt werden.', $e);

            return ['can_sync' => false, 'mode' => 'none', 'label' => 'Nicht verfügbar', 'message' => 'Der Doku-Sync-Status konnte nicht ermittelt werden.'];
        }

        return [
            'can_sync' => (bool) ($capabilities['can_sync'] ?? false),
            'mode' => (string) ($capabilities['mode'] ?? 'none'),
            'label' => (string) ($capabilities['label'] ?? ''),
            'message' => (string) ($capabilities['message'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array{success: bool, message?: string, error?: string}
     */
    public function handleAction(array $post): array
    {
        if ((string) ($post['action'] ?? '') !== 'sync_docs') {
            return ['success' => false, 'error' => 'Unbekannte oder nicht erlaubte Aktion.'];
        }

        if (!$this->canAccess() || !Auth::instance()->hasCapability('manage_system')) {
            return ['success' => false, 'error' => 'Für den Doku-Sync ist die Berechtigung „manage_system“ erforderlich.'];
        }

        if (!$this->getSyncStatus()['can_sync']) {
            return ['success' => false, 'error' => 'Doku-Sync ist auf diesem Server nicht verfügbar.'];
        }

        try {
            return $this->createSyncService()->syncDocsFromRepository()->toArray();
        } catch (\Throwable $e) {
            $this->logThrowableWarning('Doku-Sync ist fehlgeschlagen.', $e);

            return ['success' => false, 'error' => 'Doku-Sync ist fehlgeschlagen. Bitte Logs prüfen.'];
        }
    }

    private function canAccess(): bool
    {
        return class_exists(Auth::class) && Auth::instance()->isAdmin();
    }

    private function hasValidRepositoryLayout(): bool
    {
        $resolvedRepoRoot = realpath($this->repoRoot);
        $resolvedDocsRoot = file_exists($this->docsRoot) ? realpath($this->docsRoot) : $this->docsRoot;

        if ($resolvedRepoRoot === false || !is_dir($resolvedRepoRoot) || is_link($this->repoRoot)) {
            return $this->logInvalidRepositoryLayout('Dokumentationsmodul: ungültiger Repository-Root.', [
                'repo_root' => $this->repoRoot,
            ]);
        }

        if (!$this->isSupportedDocumentationRootLayout($resolvedRepoRoot)) {
            return $this->logInvalidRepositoryLayout('Dokumentationsmodul: Hosting-/Repository-Layout ungültig.', [
                'repo_root' => $resolvedRepoRoot,
            ]);
        }

        if ($resolvedDocsRoot !== $this->docsRoot && (!is_string($resolvedDocsRoot) || !str_starts_with($resolvedDocsRoot, rtrim($resolvedRepoRoot, '\\/') . DIRECTORY_SEPARATOR))) {
            return $this->logInvalidRepositoryLayout('Dokumentationsmodul: DOC-Pfad liegt außerhalb des Repository-Roots.', [
                'docs_root' => $this->docsRoot,
                'resolved_docs_root' => $resolvedDocsRoot,
            ]);
        }

        return true;
    }

    private function normalizeSelectedDocument(?string $selectedDoc): ?string
    {
        $selectedDoc = trim((string) $selectedDoc);
        if ($selectedDoc === '') {
            return null;
        }

        $selectedDoc = $this->sanitizeUiText($selectedDoc, self::MAX_SELECTED_DOC_LENGTH);
        $extension = strtolower((string) pathinfo($selectedDoc, PATHINFO_EXTENSION));

        if (!in_array($extension, ['md', 'csv'], true)) {
            return null;
        }

        return $selectedDoc;
    }

    private function normalizeDocumentExtension(string $extension): string
    {
        return in_array($extension, ['md', 'csv'], true) ? $extension : 'md';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildViewData(array $data): DocumentationViewData
    {
        return new DocumentationViewData($data);
    }

    /**
     * @return array{catalog: array<string, mixed>, selected_document: mixed}
     */
    private function loadCatalogSelection(?string $selectedDoc): array
    {
        $catalogData = $this->catalog->buildCatalog();
        $selection = $this->catalog->resolveSelection(
            $this->normalizeSelectedDocument($selectedDoc),
            $catalogData['all_docs']
        );

        return [
            'catalog' => $catalogData,
            'selected_document' => $selection['selected_document'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $catalogData
     */
    private function buildAvailableData(array $catalogData, mixed $selectedDocument): DocumentationViewData
    {
        $selectedPayload = $this->buildSelectedDocumentPayload($selectedDocument);

        return $this->buildViewData($this->buildBasePayload([
            'available' => true,
            'sections' => $catalogData['sections'],
            'featured_docs' => $catalogData['featured_docs'],
            'selected_document' => $selectedPayload['selected_document'],
            'selected_html' => $selectedPayload['selected_html'],
            'selected_raw' => $selectedPayload['selected_raw'],
            'doc_count' => $catalogData['doc_count'],
            'section_count' => $catalogData['section_count'],
            'is_selected_csv' => $selectedPayload['is_selected_csv'],
            'error' => null,
        ]));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function buildBasePayload(array $overrides = []): array
    {
        return array_merge([
            'available' => false,
            'docs_root' => $this->docsRoot,
            'repo_root' => $this->repoRoot,
            'sections' => [],
            'featured_docs' => [],
            'selected_document' => null,
            'selected_html' => '',
            'selected_raw' => '',
            'doc_count' => 0,
            'section_count' => 0,
            'is_selected_csv' => false,
            'error' => null,
        ], $overrides);
    }

    /**
     * @param mixed $selectedDocument
     * @return array{selected_document: mixed, selected_html: string, selected_raw: string, is_selected_csv: bool}
     */
    private function buildSelectedDocumentPayload(mixed $selectedDocument): array
    {
        $payload = $this->defaultSelectedDocumentPayload(is_array($selectedDocument) ? $selectedDocument : null);

        if (!is_array($selectedDocument) || empty($selectedDocument['full_path'])) {
            return $payload;
        }

        $fullPath = $this->resolveSelectedDocumentFullPath($selectedDocument);
        if ($fullPath === null) {
            return $payload;
        }

        $selectedRaw = $this->catalog->readDocumentContents($fullPath);

        $payload['selected_raw'] = $selectedRaw;
        $payload['selected_html'] = $this->renderSelectedDocumentHtml($selectedDocument, $selectedRaw);
        $payload['is_selected_csv'] = strtolower((string) ($selectedDocument['extension'] ?? 'md')) === 'csv';

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $selectedDocument
     * @return array{selected_document: mixed, selected_html: string, selected_raw: string, is_selected_csv: bool}
     */
    private function defaultSelectedDocumentPayload(?array $selectedDocument): array
    {
        return [
            'selected_document' => $selectedDocument,
            'selected_html' => '',
            'selected_raw' => '',
            'is_selected_csv' => false,
        ];
    }

    /**
     * @param array<string, mixed> $selectedDocument
     */
    private function resolveSelectedDocumentFullPath(array $selectedDocument): ?string
    {
        $fullPath = (string) ($selectedDocument['full_path'] ?? '');

        return $fullPath !== '' && is_file($fullPath) ? $fullPath : null;
    }

    /**
     * @param array<string, mixed> $selectedDocument
     */
    private function renderSelectedDocumentHtml(array $selectedDocument, string $selectedRaw): string
    {
        $extension = strtolower((string) ($selectedDocument['extension'] ?? 'md'));

        return $this->renderer->renderDocument(
            $selectedRaw,
            $this->normalizeDocumentExtension($extension),
            (string) ($selectedDocument['relative_path'] ?? '')
        );
    }

    private function validateViewRequest(): ?DocumentationViewData
    {
        if (!$this->canAccess()) {
            return $this->errorData('Zugriff verweigert.');
        }

        if (!$this->hasValidRepositoryLayout()) {
            return $this->errorData('Das Dokumentationsmodul ist lokal nicht korrekt konfiguriert.');
        }

        if (!is_dir($this->docsRoot)) {
            return $this->errorData('Das Dokumentationsverzeichnis /DOC wurde im Hosting-Stamm nicht gefunden.');
        }

        return null;
    }

    /**
     * @return array{repo_root:string,docs_root:string}
     */
    private function resolveDocumentationRoots(): array
    {
        $candidates = [
            rtrim((string) ABSPATH, '\\/'),
            rtrim((string) dirname((string) ABSPATH), '\\/'),
        ];

        $normalizedCandidates = [];
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizePath($candidate);
            if ($normalized === '' || in_array($normalized, $normalizedCandidates, true)) {
                continue;
            }

            $normalizedCandidates[] = $normalized;
        }

        foreach ($normalizedCandidates as $candidate) {
            $docsRoot = $candidate . DIRECTORY_SEPARATOR . 'DOC';
            if (is_dir($docsRoot)) {
                return [
                    'repo_root' => $candidate,
                    'docs_root' => $docsRoot,
                ];
            }
        }

        $preferredRoot = $normalizedCandidates[0] ?? $this->normalizePath((string) ABSPATH);

        return [
            'repo_root' => $preferredRoot,
            'docs_root' => $preferredRoot . DIRECTORY_SEPARATOR . 'DOC',
        ];
    }

    private function isSupportedDocumentationRootLayout(string $resolvedRepoRoot): bool
    {
        if (is_dir($resolvedRepoRoot . DIRECTORY_SEPARATOR . 'CMS')) {
            return true;
        }

        return is_file($resolvedRepoRoot . DIRECTORY_SEPARATOR . 'index.php')
            && is_file($resolvedRepoRoot . DIRECTORY_SEPARATOR . 'config.php')
            && is_dir($resolvedRepoRoot . DIRECTORY_SEPARATOR . 'core');
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $resolved = realpath($path);
        if ($resolved !== false) {
            return rtrim((string) $resolved, '\\/');
        }

        return rtrim($path, '\\/');
    }

    private function errorData(string $message): DocumentationViewData
    {
        return $this->buildViewData($this->buildBasePayload([
            'error' => $message,
        ]));
    }

    private function sanitizeUiText(string $value, int $maxLength = self::MAX_UI_ERROR_LENGTH): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logInvalidRepositoryLayout(string $message, array $context): bool
    {
        $this->logger->warning($message, $context);

        return false;
    }

    private function logThrowableWarning(string $message, \Throwable $exception): void
    {
        $this->logger->warning($message, [
            'exception' => $exception::class,
            'message' => $this->sanitizeUiText($exception->getMessage()),
        ]);
    }
}
