<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

use CMS\Database;
use CMS\AuditLogger;
use CMS\Services\AnalyticsService;
use CMS\Services\CsvExchange;
use CMS\Services\IndexingService;
use CMS\Services\PermalinkService;
use CMS\Services\RedirectService;
use CMS\Services\SEO\PostSeoMetaCsvService;
use CMS\Services\SEOService;
use CMS\Services\SeoAnalysisService;
use CMS\Services\SeoBrokenLinkService;
use CMS\Services\SeoTrendService;

final class SeoSuiteModule
{
	private const POST_META_CSV_MAX_BYTES = 5 * 1024 * 1024;

	private const META_DEFAULTS = [
		'seo_site_title_format' => '%%title%% %%sep%% %%sitename%%',
		'seo_title_separator' => '|',
		'seo_analysis_min_words' => '300',
		'seo_analysis_sentence_words' => '24',
		'seo_analysis_paragraph_words' => '120',
		'seo_default_robots_index' => '1',
		'seo_default_robots_follow' => '1',
		'seo_self_referencing_canonical' => '1',
		'seo_homepage_title' => '',
		'seo_homepage_description' => '',
		'seo_meta_description' => '',
	];

	private const SOCIAL_DEFAULTS = [
		'seo_social_default_og_type' => 'website',
		'seo_social_default_image' => '',
		'seo_social_default_twitter_card' => 'summary_large_image',
		'seo_social_brand_name' => '',
		'seo_social_facebook_page' => '',
		'seo_social_twitter_profile' => '',
		'seo_social_pinterest_rich_pins' => '0',
	];

	private const SCHEMA_DEFAULTS = [
		'seo_schema_organization_enabled' => '1',
		'seo_schema_breadcrumb_enabled' => '1',
		'seo_schema_person_enabled' => '1',
		'seo_schema_faq_enabled' => '1',
		'seo_schema_howto_enabled' => '1',
		'seo_schema_review_enabled' => '1',
		'seo_schema_event_enabled' => '1',
		'seo_schema_org_name' => '',
		'seo_schema_org_logo' => '',
	];

	private const TECHNICAL_DEFAULTS = [
		'seo_technical_auto_redirect_slug' => '1',
		'seo_technical_hreflang_enabled' => '1',
		'seo_technical_breadcrumbs_enabled' => '1',
		'seo_technical_image_alt_required' => '1',
		'seo_technical_noindex_archives' => '0',
		'seo_technical_noindex_tags' => '0',
		'seo_technical_pagination_rel' => '1',
		'seo_technical_broken_link_scan' => '1',
		'seo_indexnow_key' => '',
		'seo_indexnow_key_file' => '',
	];

	private const ANALYTICS_DEFAULTS = [
		'seo_analytics_gsc_property' => '',
		'seo_analytics_ga4_id' => '',
		'seo_analytics_matomo_url' => '',
		'seo_analytics_matomo_site_id' => '1',
		'seo_analytics_gtm_id' => '',
		'seo_analytics_fb_pixel_id' => '',
		'seo_analytics_exclude_admins' => '1',
		'seo_analytics_respect_dnt' => '1',
		'seo_analytics_anonymize_ip' => '1',
		'seo_analytics_web_vitals_enabled' => '1',
		'seo_analytics_web_vitals_sample_rate' => '100',
	];

	private const SITEMAP_EXTRA_DEFAULTS = [
		'seo_sitemap_image_enabled' => '1',
		'seo_sitemap_news_enabled' => '0',
		'seo_sitemap_news_publication_name' => '365CMS',
		'seo_sitemap_news_language' => 'de',
	];

	private const MAX_INDEXING_URLS = 100;
	private const ALLOWED_SUBMISSION_TARGETS = ['indexnow', 'google'];
	private const ALLOWED_OG_TYPES = ['website', 'article', 'profile'];
	private const ALLOWED_TWITTER_CARDS = ['summary', 'summary_large_image'];

	private const ALLOWED_CHANGEFREQ = [
		'always',
		'hourly',
		'daily',
		'weekly',
		'monthly',
		'yearly',
		'never',
	];

	private Database $db;
	private string $prefix;
	private SEOService $seoService;
	private SeoAnalysisService $analysisService;
	private AnalyticsService $analyticsService;
	private IndexingService $indexingService;
	private RedirectService $redirectService;
	private SeoBrokenLinkService $brokenLinkService;
	private SeoTrendService $trendService;
	private ?array $knownSiteAuthorities = null;

	public function __construct()
	{
		$this->db = Database::instance();
		$this->prefix = $this->db->getPrefix();
		$this->seoService = SEOService::getInstance();
		$this->analysisService = SeoAnalysisService::getInstance();
		$this->analyticsService = AnalyticsService::getInstance();
		$this->indexingService = IndexingService::getInstance();
		$this->redirectService = RedirectService::getInstance();
		$this->brokenLinkService = SeoBrokenLinkService::getInstance();
		$this->trendService = SeoTrendService::getInstance();
	}

	public function getData(string $section = 'dashboard'): array
	{
		$context = $this->buildSectionContext();
		$overview = $context['overview'];
		$decoratedAuditRows = $context['audit_rows'];

		return [
			'section' => $section,
			'overview' => $overview,
			'dashboard' => $this->getDashboardData($decoratedAuditRows, $overview),
			'analytics' => $this->getAnalyticsData($decoratedAuditRows),
			'audit' => $this->getAuditData($decoratedAuditRows),
			'meta' => $this->getMetaData($decoratedAuditRows),
			'social' => $this->getSocialData($decoratedAuditRows),
			'schema' => $this->getSchemaData($decoratedAuditRows),
			'sitemap' => $this->getSitemapData($decoratedAuditRows),
			'technical' => $this->getTechnicalData($decoratedAuditRows),
		];
	}

	public function getSectionData(string $section = 'dashboard'): array
	{
		$section = strtolower(trim($section));
		$context = $this->buildSectionContext();
		$overview = $context['overview'];
		$decoratedAuditRows = $context['audit_rows'];

		return match ($section) {
			'dashboard' => [
				'section' => 'dashboard',
				'overview' => $overview,
				'dashboard' => $this->getDashboardData($decoratedAuditRows, $overview),
			],
			'analytics' => [
				'section' => 'analytics',
				'analytics' => $this->getAnalyticsData($decoratedAuditRows),
			],
			'audit' => [
				'section' => 'audit',
				'overview' => $overview,
				'audit' => $this->getAuditData($decoratedAuditRows),
			],
			'meta' => [
				'section' => 'meta',
				'meta' => $this->getMetaData($decoratedAuditRows),
			],
			'social' => [
				'section' => 'social',
				'social' => $this->getSocialData($decoratedAuditRows),
			],
			'schema' => [
				'section' => 'schema',
				'schema' => $this->getSchemaData($decoratedAuditRows),
			],
			'sitemap' => [
				'section' => 'sitemap',
				'sitemap' => $this->getSitemapData($decoratedAuditRows),
			],
			'technical' => [
				'section' => 'technical',
				'technical' => $this->getTechnicalData($decoratedAuditRows),
			],
			default => $this->getData($section),
		};
	}

	public function handleAction(string $section, string $action, array $post): array
	{
		return match ($action) {
			'regenerate_sitemap', 'regenerate_sitemap_bundle' => $this->regenerateSitemapBundle(),
			'submit_indexing_urls' => $this->submitIndexingUrls($post),
			'submit_recent_content_indexnow' => $this->submitRecentContentIndexNow($post),
			'delete_google_url' => $this->deleteGoogleUrl($post),
			'save_google_access_token' => $this->saveGoogleAccessToken($post),
			'clear_google_access_token' => $this->clearGoogleAccessToken(),
			'save_templates' => $this->saveMetaTemplates($post),
			'save_sitemap_settings' => $this->saveSitemapSettings($post),
			'save_meta_defaults' => $this->saveMetaDefaults($post),
			'save_social_defaults' => $this->saveSocialDefaults($post),
			'save_schema_defaults' => $this->saveSchemaDefaults($post),
			'save_technical_settings' => $this->saveTechnicalSettings($post),
			'run_broken_link_scan' => $this->brokenLinkService->runScan('manual'),
			'ignore_broken_link_target' => $this->brokenLinkService->ignorePath((string)($post['target_path'] ?? '')),
			'unignore_broken_link_target' => $this->brokenLinkService->removeIgnoredPath((string)($post['target_path'] ?? '')),
			'save_analytics_settings' => $this->saveAnalyticsSettings($post),
			'save_audit_item' => $this->saveAuditItem($post),
			'save_robots' => $this->saveRobotsTxt(),
			default => ['success' => false, 'error' => 'Unbekannte SEO-Aktion für Bereich „' . $section . '“.'],
		};
	}

	public function streamPostMetaCsvExport(): never
	{
		$rows = PostSeoMetaCsvService::getInstance()->getExportRows();

		AuditLogger::instance()->log(
			AuditLogger::CAT_SETTING,
			'seo.post_meta.export.csv',
			'SEO-Metadaten der Beiträge wurden als CSV exportiert.',
			'post',
			null,
			['format' => 'csv', 'rows' => count($rows)],
			'info'
		);

		CsvExchange::stream('beitraege-seo-metadaten', PostSeoMetaCsvService::EXPORT_COLUMNS, $rows);
	}

	public function importPostMetaCsv(mixed $file): array
	{
		try {
			$content = CsvExchange::readUploadedFile($file, self::POST_META_CSV_MAX_BYTES);
		} catch (\InvalidArgumentException $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}

		if (function_exists('set_time_limit')) {
			set_time_limit(300);
		}

		$result = PostSeoMetaCsvService::getInstance()->importFromCsv($content);

		AuditLogger::instance()->log(
			AuditLogger::CAT_SETTING,
			'seo.post_meta.import.csv',
			'SEO-Metadaten der Beiträge wurden per CSV importiert.',
			'post',
			null,
			array_merge(['format' => 'csv'], is_array($result['summary'] ?? null) ? $result['summary'] : []),
			!empty($result['success']) ? 'info' : 'warning'
		);

		return $result;
	}

	public function regenerateSitemapBundle(): array
	{
		try {
			$bundleSaved = (bool)$this->seoService->saveSitemapBundle();
			$lastError = $this->sanitizeLogValue((string)$this->seoService->getLastSitemapError(), 240);

			if (!$bundleSaved) {
				AuditLogger::instance()->log(
					AuditLogger::CAT_SETTING,
					'seo.sitemap.regenerate_failed',
					'Sitemap-Bundle konnte nicht geschrieben werden',
					'seo',
					null,
					['reason' => $lastError],
					'warning'
				);
			}

			return $bundleSaved
				? ['success' => true, 'message' => 'Sitemap-Bundle neu generiert.']
				: ['success' => false, 'error' => 'Sitemap-Bundle konnte nicht geschrieben werden. Bitte Serverrechte und Zielverzeichnis prüfen.'];
		} catch (\Throwable $e) {
			AuditLogger::instance()->log(
				AuditLogger::CAT_SETTING,
				'seo.sitemap.regenerate_exception',
				'Sitemap-Bundle-Generierung fehlgeschlagen',
				'seo',
				null,
				['exception' => $this->sanitizeLogValue($e->getMessage(), 240)],
				'error'
			);

			return ['success' => false, 'error' => 'Sitemap-Bundle konnte nicht generiert werden. Bitte Logs prüfen.'];
		}
	}

	public function saveRobotsTxt(): array
	{
		return $this->seoService->saveRobotsTxt()
			? ['success' => true, 'message' => 'robots.txt wurde aktualisiert.']
			: ['success' => false, 'error' => 'robots.txt konnte nicht gespeichert werden.'];
	}

	public function saveMetaTemplates(array $post): array
	{
		$this->persistSettings([
			'seo_site_title_format' => trim((string)($post['site_title_format'] ?? self::META_DEFAULTS['seo_site_title_format'])),
			'seo_title_separator' => trim((string)($post['title_separator'] ?? self::META_DEFAULTS['seo_title_separator'])),
			'seo_analysis_min_words' => (string)max(100, (int)($post['analysis_min_words'] ?? 300)),
			'seo_analysis_sentence_words' => (string)max(12, (int)($post['analysis_sentence_words'] ?? 24)),
			'seo_analysis_paragraph_words' => (string)max(40, (int)($post['analysis_paragraph_words'] ?? 120)),
		]);

		return ['success' => true, 'message' => 'Meta-Vorlagen und Analyse-Schwellen gespeichert.'];
	}

	public function saveMetaDefaults(array $post): array
	{
		$this->persistSettings([
			'seo_homepage_title' => trim((string)($post['homepage_title'] ?? '')),
			'seo_homepage_description' => trim((string)($post['homepage_description'] ?? '')),
			'seo_meta_description' => trim((string)($post['meta_description'] ?? '')),
			'seo_default_robots_index' => !empty($post['default_robots_index']) ? '1' : '0',
			'seo_default_robots_follow' => !empty($post['default_robots_follow']) ? '1' : '0',
			'seo_self_referencing_canonical' => !empty($post['self_referencing_canonical']) ? '1' : '0',
		]);

		return ['success' => true, 'message' => 'Globale Meta-Standards gespeichert.'];
	}

	public function saveSocialDefaults(array $post): array
	{
		$this->persistSettings([
			'seo_social_default_og_type' => $this->normalizeAllowedValue((string)($post['default_og_type'] ?? 'website'), self::ALLOWED_OG_TYPES, 'website'),
			'seo_social_default_image' => $this->normalizeOptionalUrl((string)($post['default_image'] ?? ''), true),
			'seo_social_default_twitter_card' => $this->normalizeAllowedValue((string)($post['default_twitter_card'] ?? 'summary_large_image'), self::ALLOWED_TWITTER_CARDS, 'summary_large_image'),
			'seo_social_brand_name' => trim((string)($post['brand_name'] ?? '')),
			'seo_social_facebook_page' => $this->normalizeOptionalUrl((string)($post['facebook_page'] ?? ''), false),
			'seo_social_twitter_profile' => trim((string)($post['twitter_profile'] ?? '')),
			'seo_social_pinterest_rich_pins' => !empty($post['pinterest_rich_pins']) ? '1' : '0',
		]);

		return ['success' => true, 'message' => 'Social-Media-Defaults gespeichert.'];
	}

	public function saveSchemaDefaults(array $post): array
	{
		$this->persistSettings([
			'seo_schema_organization_enabled' => !empty($post['organization_enabled']) ? '1' : '0',
			'seo_schema_breadcrumb_enabled' => !empty($post['breadcrumb_enabled']) ? '1' : '0',
			'seo_schema_person_enabled' => !empty($post['person_enabled']) ? '1' : '0',
			'seo_schema_faq_enabled' => !empty($post['faq_enabled']) ? '1' : '0',
			'seo_schema_howto_enabled' => !empty($post['howto_enabled']) ? '1' : '0',
			'seo_schema_review_enabled' => !empty($post['review_enabled']) ? '1' : '0',
			'seo_schema_event_enabled' => !empty($post['event_enabled']) ? '1' : '0',
			'seo_schema_org_name' => trim((string)($post['org_name'] ?? '')),
			'seo_schema_org_logo' => $this->normalizeOptionalUrl((string)($post['org_logo'] ?? ''), true),
		]);

		return ['success' => true, 'message' => 'Schema-Standards gespeichert.'];
	}

	public function saveTechnicalSettings(array $post): array
	{
		$rawIndexNowKey = trim((string)($post['indexnow_key'] ?? ''));
		$indexNowKey = $this->normalizeIndexNowKey($rawIndexNowKey);
		if ($rawIndexNowKey !== '' && $indexNowKey === '') {
			return ['success' => false, 'error' => 'Der IndexNow-API-Key enthält ungültige Zeichen. Erlaubt sind Buchstaben, Zahlen, Bindestriche und Unterstriche.'];
		}

		$availableRootTxtFiles = $this->indexingService->getIndexNowRootTxtFiles();
		$rawIndexNowKeyFile = trim((string)($post['indexnow_key_file'] ?? ''));
		$indexNowKeyFile = $this->normalizeIndexNowKeyFile($rawIndexNowKeyFile, $availableRootTxtFiles);
		if ($rawIndexNowKeyFile !== '' && $indexNowKeyFile === '') {
			return ['success' => false, 'error' => 'Die ausgewählte Root-TXT-Datei ist ungültig oder nicht mehr vorhanden.'];
		}

		$this->persistSettings([
			'seo_technical_auto_redirect_slug' => !empty($post['auto_redirect_slug']) ? '1' : '0',
			'seo_technical_hreflang_enabled' => !empty($post['hreflang_enabled']) ? '1' : '0',
			'seo_technical_breadcrumbs_enabled' => !empty($post['breadcrumbs_enabled']) ? '1' : '0',
			'seo_technical_image_alt_required' => !empty($post['image_alt_required']) ? '1' : '0',
			'seo_technical_noindex_archives' => !empty($post['noindex_archives']) ? '1' : '0',
			'seo_technical_noindex_tags' => !empty($post['noindex_tags']) ? '1' : '0',
			'seo_technical_pagination_rel' => !empty($post['pagination_rel']) ? '1' : '0',
			'seo_technical_broken_link_scan' => !empty($post['broken_link_scan']) ? '1' : '0',
			'seo_indexnow_key' => $indexNowKey,
			'seo_indexnow_key_file' => $indexNowKeyFile,
		]);

		$indexNowStatus = $this->indexingService->getIndexNowConfigurationStatus();
		$message = 'Technische SEO-Einstellungen gespeichert.';

		if (!$indexNowStatus['key_available']) {
			$message .= ' IndexNow ist noch nicht aktiv, da kein API-Key hinterlegt ist.';
		} elseif ($indexNowStatus['selected_root_file'] !== '' && !$indexNowStatus['selected_root_file_valid']) {
			$message .= ' Die ausgewählte IndexNow-TXT-Datei wurde gespeichert, besteht die Prüfung aber noch nicht vollständig.';
		} elseif ($indexNowStatus['selected_root_file'] !== '') {
			$message .= ' Die ausgewählte IndexNow-TXT-Datei wurde erfolgreich geprüft.';
		} else {
			$message .= ' Die dynamische IndexNow-Keydatei ist aktiv; optional kann zusätzlich eine physische Root-TXT-Datei gewählt werden.';
		}

		return ['success' => true, 'message' => $message];
	}

	public function saveAnalyticsSettings(array $post): array
	{
		$this->persistSettings([
			'seo_analytics_gsc_property' => trim((string)($post['gsc_property'] ?? '')),
			'seo_analytics_ga4_id' => $this->normalizeTrackingId((string)($post['ga4_id'] ?? ''), '/^G-[A-Z0-9\-]+$/i'),
			'seo_analytics_matomo_url' => $this->normalizeOptionalUrl((string)($post['matomo_url'] ?? ''), false),
			'seo_analytics_matomo_site_id' => $this->normalizePositiveIntString($post['matomo_site_id'] ?? '1', '1'),
			'seo_analytics_gtm_id' => $this->normalizeTrackingId((string)($post['gtm_id'] ?? ''), '/^GTM-[A-Z0-9\-]+$/i'),
			'seo_analytics_fb_pixel_id' => $this->normalizeTrackingId((string)($post['fb_pixel_id'] ?? ''), '/^[0-9]{5,20}$/'),
			'seo_analytics_exclude_admins' => !empty($post['exclude_admins']) ? '1' : '0',
			'seo_analytics_respect_dnt' => !empty($post['respect_dnt']) ? '1' : '0',
			'seo_analytics_anonymize_ip' => !empty($post['anonymize_ip']) ? '1' : '0',
			'seo_analytics_web_vitals_enabled' => !empty($post['web_vitals_enabled']) ? '1' : '0',
			'seo_analytics_web_vitals_sample_rate' => (string)max(1, min(100, (int)($post['web_vitals_sample_rate'] ?? 100))),
		]);

		return ['success' => true, 'message' => 'Analytics- und Tracking-Einstellungen gespeichert.'];
	}

	public function saveSitemapSettings(array $post): array
	{
		$this->persistSettings([
			'seo_sitemap_pages_priority' => $this->normalizeSitemapPriority((string)($post['pages_priority'] ?? '0.8'), '0.8'),
			'seo_sitemap_pages_changefreq' => $this->normalizeSitemapChangefreq((string)($post['pages_changefreq'] ?? 'weekly'), 'weekly'),
			'seo_sitemap_posts_priority' => $this->normalizeSitemapPriority((string)($post['posts_priority'] ?? '0.6'), '0.6'),
			'seo_sitemap_posts_changefreq' => $this->normalizeSitemapChangefreq((string)($post['posts_changefreq'] ?? 'monthly'), 'monthly'),
			'seo_sitemap_ping_google' => !empty($post['ping_google']) ? '1' : '0',
			'seo_sitemap_ping_bing' => !empty($post['ping_bing']) ? '1' : '0',
			'seo_sitemap_image_enabled' => !empty($post['image_enabled']) ? '1' : '0',
			'seo_sitemap_news_enabled' => !empty($post['news_enabled']) ? '1' : '0',
			'seo_sitemap_news_publication_name' => trim((string)($post['news_publication_name'] ?? '365CMS')),
			'seo_sitemap_news_language' => $this->normalizeLanguageCode((string)($post['news_language'] ?? 'de')),
		]);

		return ['success' => true, 'message' => 'Sitemap-Einstellungen gespeichert.'];
	}

	public function submitIndexingUrls(array $post): array
	{
		$urls = $this->parseSubmittedUrls((string)($post['urls'] ?? ''));
		if ($urls === []) {
			return ['success' => false, 'error' => 'Bitte mindestens eine gültige URL angeben.'];
		}

		$rawUrls = implode("\n", $urls);

		$targets = $post['submission_target'] ?? [];
		$targets = is_array($targets) ? $targets : [$targets];
		$targets = array_values(array_unique(array_filter(array_map(
			fn($target): string => $this->normalizeAllowedValue((string)$target, self::ALLOWED_SUBMISSION_TARGETS, ''),
			$targets
		))));

		if ($targets === []) {
			return ['success' => false, 'error' => 'Bitte mindestens ein Ziel für die URL-Submission wählen.'];
		}

		$messages = [];
		$errors = [];

		if (in_array('indexnow', $targets, true)) {
			if ($this->indexingService->submitIndexNow($rawUrls)) {
				$messages[] = 'IndexNow wurde angestoßen.';
			} else {
				$errors[] = 'IndexNow konnte die URLs nicht übermitteln.';
			}
		}

		if (in_array('google', $targets, true)) {
			$accessToken = trim((string)($post['google_access_token'] ?? ''));
			if ($accessToken === '' && !$this->indexingService->hasGoogleAccessToken()) {
				$errors[] = 'Für Google fehlt ein Access-Token (weder eingegeben noch dauerhaft gespeichert).';
			} elseif ($this->indexingService->submitGoogle($rawUrls, $accessToken)) {
				$messages[] = 'Google URL Notification wurde angestoßen.';
			} else {
				$errors[] = 'Google konnte die URLs nicht verarbeiten.';
			}
		}

		if ($messages !== [] && $errors === []) {
			return ['success' => true, 'message' => implode(' ', $messages)];
		}

		if ($messages !== [] && $errors !== []) {
			return ['success' => false, 'error' => implode(' ', $messages) . ' ' . implode(' ', $errors)];
		}

		return ['success' => false, 'error' => implode(' ', $errors)];
	}

	public function submitRecentContentIndexNow(array $post): array
	{
		$range = $this->normalizeAllowedValue(
			(string)($post['recent_content_range'] ?? ''),
			array_keys($this->indexingService->getRecentContentRangeOptions()),
			''
		);

		if ($range === '') {
			return ['success' => false, 'error' => 'Bitte einen gültigen Zeitraum auswählen.'];
		}

		$targets = $post['recent_content_target'] ?? [];
		$targets = is_array($targets) ? $targets : [$targets];
		$targets = array_values(array_unique(array_filter(array_map(
			fn($target): string => $this->normalizeAllowedValue((string)$target, $this->indexingService->getRecentContentTargetOptions(), ''),
			$targets
		))));

		if ($targets === []) {
			return ['success' => false, 'error' => 'Bitte mindestens ein Ziel für die Meldung auswählen.'];
		}

		$result = $this->indexingService->submitRecentContent($range, $targets);

		if (!empty($result['success'])) {
			AuditLogger::instance()->log(
				AuditLogger::CAT_SETTING,
				'seo.indexnow.recent_content_submitted',
				'Kürzlich veröffentlichte Inhalte gemeldet',
				'seo',
				null,
				['range' => $range, 'targets' => $targets, 'count' => (int)($result['count'] ?? 0)],
				'info'
			);

			return ['success' => true, 'message' => (string)($result['message'] ?? 'Meldung wurde übermittelt.')];
		}

		return ['success' => false, 'error' => (string)($result['error'] ?? 'Meldung konnte nicht übermittelt werden.')];
	}

	public function deleteGoogleUrl(array $post): array
	{
		$url = $this->normalizeIndexingUrl((string)($post['google_delete_url'] ?? ''));
		$accessToken = trim((string)($post['google_access_token'] ?? ''));

		if ($url === '' || ($accessToken === '' && !$this->indexingService->hasGoogleAccessToken())) {
			return ['success' => false, 'error' => 'Für das Entfernen aus Google werden eine URL sowie ein Access-Token (eingegeben oder dauerhaft gespeichert) benötigt.'];
		}

		return $this->indexingService->deleteGoogle($url, $accessToken)
			? ['success' => true, 'message' => 'Google wurde über die Entfernung der URL informiert.']
			: ['success' => false, 'error' => 'Google konnte die URL nicht aus dem Index entfernen.'];
	}

	public function saveGoogleAccessToken(array $post): array
	{
		$accessToken = trim((string)($post['google_access_token'] ?? ''));
		if ($accessToken === '') {
			return ['success' => false, 'error' => 'Bitte ein Google-Access-Token eingeben.'];
		}

		if (!$this->indexingService->saveGoogleAccessToken($accessToken)) {
			return ['success' => false, 'error' => 'Google-Access-Token konnte nicht gespeichert werden.'];
		}

		AuditLogger::instance()->log(
			AuditLogger::CAT_SETTING,
			'seo.indexing.google_token_saved',
			'Google-Access-Token dauerhaft gespeichert',
			'seo',
			null,
			[],
			'info'
		);

		return ['success' => true, 'message' => 'Google-Access-Token wurde verschlüsselt gespeichert und wird künftig automatisch verwendet.'];
	}

	public function clearGoogleAccessToken(): array
	{
		if (!$this->indexingService->clearGoogleAccessToken()) {
			return ['success' => false, 'error' => 'Google-Access-Token konnte nicht entfernt werden.'];
		}

		AuditLogger::instance()->log(
			AuditLogger::CAT_SETTING,
			'seo.indexing.google_token_cleared',
			'Google-Access-Token entfernt',
			'seo',
			null,
			[],
			'info'
		);

		return ['success' => true, 'message' => 'Gespeichertes Google-Access-Token wurde entfernt.'];
	}

	public function saveAuditItem(array $post): array
	{
		$contentType = (string)($post['content_type'] ?? '');
		$id = (int)($post['content_id'] ?? 0);
		if (!in_array($contentType, ['page', 'post'], true) || $id <= 0) {
			return ['success' => false, 'error' => 'Ungültiger Inhalt für das SEO-Audit.'];
		}

		$table = $contentType === 'page' ? 'pages' : 'posts';
		$this->db->execute(
			"UPDATE {$this->prefix}{$table} SET meta_title = ?, meta_description = ? WHERE id = ?",
			[trim((string)($post['meta_title'] ?? '')), trim((string)($post['meta_description'] ?? '')), $id]
		);

		$this->seoService->saveContentMeta($contentType, $id, [
			'focus_keyphrase' => (string)($post['focus_keyphrase'] ?? ''),
			'keywords' => (string)($post['keywords'] ?? ''),
			'canonical_url' => $this->normalizeOptionalUrl((string)($post['canonical_url'] ?? ''), false),
			'robots_index' => !empty($post['robots_index']),
			'robots_follow' => !empty($post['robots_follow']),
			'og_title' => (string)($post['og_title'] ?? ''),
			'og_description' => (string)($post['og_description'] ?? ''),
			'og_image' => $this->normalizeOptionalUrl((string)($post['og_image'] ?? ''), true),
			'twitter_title' => (string)($post['twitter_title'] ?? ''),
			'twitter_description' => (string)($post['twitter_description'] ?? ''),
			'twitter_image' => $this->normalizeOptionalUrl((string)($post['twitter_image'] ?? ''), true),
			'twitter_card' => $this->normalizeAllowedValue((string)($post['twitter_card'] ?? ''), self::ALLOWED_TWITTER_CARDS, 'summary_large_image'),
			'schema_type' => \CMS\Services\SEO\SeoSchemaRenderer::selectableTypeFor((string)($post['schema_type'] ?? ''), $contentType),
			'sitemap_priority' => $this->normalizeSitemapPriority((string)($post['sitemap_priority'] ?? ''), ''),
			'sitemap_changefreq' => $this->normalizeSitemapChangefreq((string)($post['sitemap_changefreq'] ?? ''), ''),
			'hreflang_group' => (string)($post['hreflang_group'] ?? ''),
		]);

		return ['success' => true, 'message' => 'SEO-Daten wurden aktualisiert.'];
	}

	private function getDashboardData(array $auditRows, array $overview): array
	{
		$topIssues = [];
		foreach ($auditRows as $row) {
			foreach ((array)($row['analysis']['rules'] ?? []) as $rule) {
				if (!empty($rule['passed'])) {
					continue;
				}
				$key = (string)($rule['label'] ?? 'Hinweis');
				if (!isset($topIssues[$key])) {
					$topIssues[$key] = 0;
				}
				$topIssues[$key]++;
			}
		}
		arsort($topIssues);

		$contentBuckets = [
			'publish_ready' => 0,
			'needs_meta' => 0,
			'needs_links' => 0,
			'needs_readability' => 0,
		];

		foreach ($auditRows as $row) {
			$analysis = (array)($row['analysis'] ?? []);
			$required = (array)($analysis['required_fields'] ?? []);
			$stats = (array)($analysis['stats'] ?? []);
			$score = (int)($analysis['score'] ?? 0);

			if ($score >= 80 && !in_array(false, $required, true)) {
				$contentBuckets['publish_ready']++;
			}
			if (in_array(false, $required, true)) {
				$contentBuckets['needs_meta']++;
			}
			if ((int)($stats['internal_links'] ?? 0) < 1) {
				$contentBuckets['needs_links']++;
			}
			if ((int)($stats['long_sentences'] ?? 0) > 3 || (int)($stats['long_paragraphs'] ?? 0) > 2) {
				$contentBuckets['needs_readability']++;
			}
		}

		return [
			'top_issues' => array_slice($topIssues, 0, 6, true),
			'content_buckets' => $contentBuckets,
			'recent_critical' => array_slice(array_values(array_filter($auditRows, static fn(array $row): bool => (int)($row['analysis']['score'] ?? 0) < 55)), 0, 8),
			'status' => $overview['status'],
			'trends' => $this->trendService->buildDashboardTrendData($auditRows, $overview),
		];
	}

	private function getAnalyticsData(array $auditRows): array
	{
		$hasPageViews = $this->db->tableExists($this->prefix . 'page_views');
		$dailyTraffic = [];
		$topPages = [];
		$referrers = [];
		$backlinks = [];
		$knownSiteAuthorities = $this->getKnownSiteAuthorities();

		if ($hasPageViews) {
			$dailyTraffic = array_map(static fn(object $row): array => [
				'day' => (string)($row->day ?? ''),
				'views' => (int)($row->views ?? 0),
			], $this->db->get_results(
				"SELECT DATE(visited_at) AS day, COUNT(*) AS views
				 FROM {$this->prefix}page_views
				 WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
				 GROUP BY DATE(visited_at)
				 ORDER BY day ASC"
			) ?: []);

			$topPages = array_map(static fn(object $row): array => [
				'page_slug' => (string)($row->page_slug ?? ''),
				'views' => (int)($row->views ?? 0),
			], $this->db->get_results(
				"SELECT page_slug, COUNT(*) AS views
				 FROM {$this->prefix}page_views
				 WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
				 GROUP BY page_slug
				 ORDER BY views DESC
				 LIMIT 10"
			) ?: []);

			$referrers = array_map(static fn(object $row): array => [
				'referrer' => (string)($row->referrer ?? ''),
				'cnt' => (int)($row->cnt ?? 0),
			], $this->db->get_results(
				"SELECT referrer, COUNT(*) AS cnt
				 FROM {$this->prefix}page_views
				 WHERE referrer IS NOT NULL AND referrer != ''
				   AND visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
				 GROUP BY referrer
				 ORDER BY cnt DESC
				 LIMIT 20"
			) ?: []);

			$domains = [];
			foreach ($referrers as $row) {
				$referrer = trim((string)($row['referrer'] ?? ''));
				$authority = $this->normalizeComparableAuthority($referrer);
				if ($authority === '' || in_array($authority, $knownSiteAuthorities, true)) {
					continue;
				}

				$host = strtolower((string)parse_url($referrer, PHP_URL_HOST));
				if ($host === '') {
					continue;
				}
				$domains[$host] = ($domains[$host] ?? 0) + (int)$row['cnt'];
			}
			arsort($domains);
			foreach (array_slice($domains, 0, 10, true) as $domain => $hits) {
				$backlinks[] = ['domain' => $domain, 'hits' => $hits];
			}
		}

		$internalLinkSuggestions = [];
		foreach ($auditRows as $row) {
			$stats = (array)($row['analysis']['stats'] ?? []);
			if ((int)($stats['internal_links'] ?? 0) > 0 || (int)($stats['word_count'] ?? 0) < 120) {
				continue;
			}
			$internalLinkSuggestions[] = [
				'title' => (string)($row['title'] ?? ''),
				'type' => (string)($row['type'] ?? ''),
				'slug' => (string)($row['slug'] ?? ''),
				'score' => (int)($row['analysis']['score'] ?? 0),
			];
		}

		return [
			'visitor_stats' => $this->analyticsService->getVisitorStats(30),
			'daily_traffic' => $dailyTraffic,
			'top_pages' => $topPages,
			'feature_usage' => $this->analyticsService->getFeatureUsageSummary(30),
			'referrers' => array_slice($referrers, 0, 10),
			'backlinks' => $backlinks,
			'internal_link_suggestions' => array_slice($internalLinkSuggestions, 0, 10),
			'core_web_vitals' => $this->analyticsService->getCoreWebVitals(30),
			'tracking_settings' => $this->loadSettings(self::ANALYTICS_DEFAULTS),
			'has_page_views' => $hasPageViews,
		];
	}

	private function getAuditData(array $auditRows): array
	{
		return [
			'rows' => $auditRows,
			'warning_count' => count(array_filter($auditRows, static fn(array $row): bool => (int)($row['analysis']['score'] ?? 0) >= 55 && (int)($row['analysis']['score'] ?? 0) < 80)),
			'critical_count' => count(array_filter($auditRows, static fn(array $row): bool => (int)($row['analysis']['score'] ?? 0) < 55)),
		];
	}

	private function getMetaData(array $auditRows): array
	{
		$examples = array_slice($auditRows, 0, 4);
		$settings = $this->loadSettings(self::META_DEFAULTS);
		$socialSettings = $this->loadSettings(self::SOCIAL_DEFAULTS);

		return [
			'settings' => $settings,
			'variables' => [
				['token' => '%%title%%', 'description' => 'Titel des Inhalts'],
				['token' => '%%sitename%%', 'description' => 'Website-Name'],
				['token' => '%%sep%%', 'description' => 'Trennzeichen'],
				['token' => '%%excerpt%%', 'description' => 'Kurzfassung / erster Absatz'],
				['token' => '%%slug%%', 'description' => 'Slug/URL-Segment'],
			],
			'examples' => $examples,
			'site_name' => $this->resolveSiteName(),
			'default_social_image' => (string)($socialSettings['seo_social_default_image'] ?? ''),
			'preview_contexts' => $this->buildMetaPreviewContexts($settings, $socialSettings),
		];
	}

	/**
	 * @param array<string, string> $settings
	 * @param array<string, string> $socialSettings
	 * @return list<array<string, string>>
	 */
	private function buildMetaPreviewContexts(array $settings, array $socialSettings): array
	{
		$siteName = $this->resolveSiteName();
		$globalDescription = trim((string)($settings['seo_meta_description'] ?? ''));
		$homepageTitle = trim((string)($settings['seo_homepage_title'] ?? ''));
		$homepageDescription = trim((string)($settings['seo_homepage_description'] ?? ''));
		$socialImage = trim((string)($socialSettings['seo_social_default_image'] ?? ''));

		$categorySample = $this->loadFirstTaxonomySample('post_categories', 'Kategorie', 'news');
		$tagSample = $this->loadFirstTaxonomySample('post_tags', 'Tag', 'update');

		return [
			[
				'key' => 'homepage',
				'label' => 'Startseite',
				'title' => $homepageTitle !== '' ? $homepageTitle : $siteName,
				'description' => $homepageDescription !== '' ? $homepageDescription : ($globalDescription !== '' ? $globalDescription : 'Startseite mit den wichtigsten Inhalten und Einstiegen der Website.'),
				'url' => $this->buildPublicPreviewUrl('/'),
				'slug' => '',
				'excerpt' => $homepageDescription !== '' ? $homepageDescription : $globalDescription,
				'social_image' => $socialImage,
			],
			[
				'key' => 'blog',
				'label' => 'Blog-Archiv',
				'title' => 'Blog',
				'description' => $globalDescription !== '' ? $globalDescription : 'Aktuelle Beiträge, News und redaktionelle Inhalte der Website.',
				'url' => $this->buildPublicPreviewUrl('/blog'),
				'slug' => 'blog',
				'excerpt' => $globalDescription,
				'social_image' => $socialImage,
			],
			[
				'key' => 'category',
				'label' => 'Kategorie-Archiv',
				'title' => (string)($categorySample['name'] ?? 'Kategorie'),
				'description' => $globalDescription !== '' ? $globalDescription : 'Archivansicht für thematisch gebündelte Beiträge.',
				'url' => $this->buildArchivePreviewUrl('category', (string)($categorySample['slug'] ?? 'news')),
				'slug' => (string)($categorySample['slug'] ?? 'news'),
				'excerpt' => $globalDescription,
				'social_image' => $socialImage,
			],
			[
				'key' => 'tag',
				'label' => 'Tag-Archiv',
				'title' => (string)($tagSample['name'] ?? 'Tag'),
				'description' => $globalDescription !== '' ? $globalDescription : 'Archivansicht für Beiträge mit gemeinsamem Schlagwort.',
				'url' => $this->buildArchivePreviewUrl('tag', (string)($tagSample['slug'] ?? 'update')),
				'slug' => (string)($tagSample['slug'] ?? 'update'),
				'excerpt' => $globalDescription,
				'social_image' => $socialImage,
			],
		];
	}

	/**
	 * @return array{name: string, slug: string}
	 */
	private function loadFirstTaxonomySample(string $table, string $fallbackName, string $fallbackSlug): array
	{
		try {
			$row = $this->db->get_row(
				"SELECT name, slug FROM {$this->prefix}{$table} ORDER BY name ASC LIMIT 1"
			);

			$name = trim((string)($row->name ?? ''));
			$slug = trim((string)($row->slug ?? ''));
			if ($name !== '' && $slug !== '') {
				return ['name' => $name, 'slug' => $slug];
			}
		} catch (\Throwable) {
			// Fail-soft: Preview bleibt mit Fallback-Beispielen nutzbar.
		}

		return [
			'name' => $fallbackName,
			'slug' => $fallbackSlug,
		];
	}

	private function buildArchivePreviewUrl(string $type, string $slug): string
	{
		$normalizedType = strtolower(trim($type));
		$normalizedSlug = trim($slug, "/ \t\n\r\0\x0B");

		if (function_exists('cms_get_archive_url')) {
			try {
				$url = (string)cms_get_archive_url($normalizedType, $normalizedSlug);
				if ($url !== '') {
					return $url;
				}
			} catch (\Throwable) {
				// Fallback unten.
			}
		}

		$fallbackBase = $normalizedType === 'tag' ? 'tag' : 'category';
		$fallbackPath = '/' . $fallbackBase . ($normalizedSlug !== '' ? '/' . rawurlencode($normalizedSlug) : '');

		return $this->buildPublicPreviewUrl($fallbackPath);
	}

	private function buildPublicPreviewUrl(string $path): string
	{
		$normalizedPath = '/' . ltrim(trim($path), '/');
		$normalizedPath = $normalizedPath === '//' ? '/' : $normalizedPath;

		if (function_exists('cms_runtime_base_url')) {
			try {
				$url = (string)cms_runtime_base_url(ltrim($normalizedPath, '/'));
				if ($url !== '') {
					return $url;
				}
			} catch (\Throwable) {
				// Fallback unten.
			}
		}

		if (function_exists('home_url')) {
			try {
				$url = (string)home_url(ltrim($normalizedPath, '/'));
				if ($url !== '') {
					return $url;
				}
			} catch (\Throwable) {
				// Fallback unten.
			}
		}

		$siteUrl = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';

		return $siteUrl !== '' ? $siteUrl . ($normalizedPath === '/' ? '/' : $normalizedPath) : $normalizedPath;
	}

	private function resolveSiteName(): string
	{
		$fallback = defined('SITE_NAME') ? (string)SITE_NAME : '365CMS';
		$settings = $this->loadSettings(['site_name' => $fallback]);
		$siteName = trim((string)($settings['site_name'] ?? $fallback));

		return $siteName !== '' ? $siteName : $fallback;
	}

	private function getSocialData(array $auditRows): array
	{
		$settings = $this->loadSettings(self::SOCIAL_DEFAULTS);
		$examples = array_slice(array_values(array_filter($auditRows, static fn(array $row): bool => trim((string)($row['og_image'] ?? '')) !== '' || trim((string)($row['featured_image'] ?? '')) !== '')), 0, 4);

		return [
			'settings' => $settings,
			'examples' => $examples,
			'coverage' => [
				'og_images' => count(array_filter($auditRows, static fn(array $row): bool => trim((string)($row['og_image'] ?? '')) !== '' || trim((string)($row['featured_image'] ?? '')) !== '')),
				'twitter_titles' => count(array_filter($auditRows, static fn(array $row): bool => trim((string)($row['twitter_title'] ?? '')) !== '')),
				'twitter_descriptions' => count(array_filter($auditRows, static fn(array $row): bool => trim((string)($row['twitter_description'] ?? '')) !== '')),
			],
		];
	}

	private function getSchemaData(array $auditRows): array
	{
		$settings = $this->loadSettings(self::SCHEMA_DEFAULTS);
		$distribution = array_map(static fn(object $row): array => [
			'schema_type' => (string)($row->schema_type ?? 'WebPage'),
			'count' => (int)($row->count ?? 0),
		], $this->db->get_results(
			"SELECT COALESCE(NULLIF(schema_type, ''), 'WebPage') AS schema_type, COUNT(*) AS count
			 FROM {$this->prefix}seo_meta
			 GROUP BY COALESCE(NULLIF(schema_type, ''), 'WebPage')
			 ORDER BY count DESC"
		) ?: []);

		return [
			'settings' => $settings,
			'distribution' => $distribution,
			'renderer' => [
				'name' => 'melbahja/seo',
				'status' => is_dir(ABSPATH . 'assets/melbahja-seo') ? 'aktiv' : 'fehlt',
				'schema_mode' => 'Melbahja\\Seo\\Schema + Thing',
				'breadcrumbs_enabled' => !empty($settings['seo_schema_breadcrumb_enabled']),
			],
			'supported_types' => ['Article', 'BlogPosting', 'WebPage', 'BreadcrumbList', 'Organization', 'Person', 'FAQPage', 'HowTo', 'Review', 'Event'],
			'examples' => array_slice($auditRows, 0, 8),
		];
	}

	private function getSitemapData(array $auditRows): array
	{
		$settings = array_merge($this->seoService->getSitemapSettings(), $this->loadSettings(self::SITEMAP_EXTRA_DEFAULTS));
		$indexNowStatus = $this->indexingService->getIndexNowConfigurationStatus();

		$eligibleRows = array_values(array_filter($auditRows, static function (array $row): bool {
			$type = (string) ($row['type'] ?? '');
			$status = (string) ($row['status'] ?? '');

			if ($type === 'page') {
				return $status === 'published';
			}

			if ($type === 'post') {
				return \cms_post_is_publicly_visible($row);
			}

			return false;
		}));

		return [
			'settings' => $settings,
			'files' => $this->getSitemapFilesStatus(),
			'indexing' => [
				'indexnow_available' => $indexNowStatus['key_available'],
				'indexnow_key_file_active' => $indexNowStatus['dynamic_key_file_active'],
				'indexnow_key_url' => $indexNowStatus['dynamic_key_file_url'],
				'indexnow_selected_root_file' => $indexNowStatus['selected_root_file'],
				'indexnow_selected_root_file_url' => $indexNowStatus['selected_root_file_url'],
				'indexnow_selected_root_file_valid' => $indexNowStatus['selected_root_file_valid'],
				'indexnow_ready_for_submission' => $indexNowStatus['ready_for_submission'],
				'indexnow_validation_errors' => $indexNowStatus['validation_errors'],
				'indexnow_validation_notes' => $indexNowStatus['validation_notes'],
				'google_access_token_available' => $this->indexingService->hasGoogleAccessToken(),
				'recent_content_ranges' => $this->indexingService->getRecentContentRangeOptions(),
				'recent_content_targets' => $this->indexingService->getRecentContentTargetOptions(),
				'engines' => ['IndexNow', 'Google Indexing API'],
				'notes' => [
					'IndexNow-Key kann jetzt direkt im SEO-Bereich gepflegt werden.',
					'Keydatei wird bei gesetztem Schlüssel dynamisch vom Core ausgeliefert.',
					'Optional kann zusätzlich eine physische Root-TXT-Datei geprüft werden.',
					'Google-Access-Token kann dauerhaft (verschlüsselt) gespeichert oder pro Aktion manuell überschrieben werden.',
				],
			],
			'counts' => [
				'pages' => count(array_filter($eligibleRows, static fn(array $row): bool => ($row['type'] ?? '') === 'page')),
				'posts' => count(array_filter($eligibleRows, static fn(array $row): bool => ($row['type'] ?? '') === 'post')),
				'images' => count(array_filter($eligibleRows, static fn(array $row): bool => trim((string)($row['og_image'] ?? '')) !== '' || trim((string)($row['featured_image'] ?? '')) !== '')),
				'news_candidates' => count(array_filter($eligibleRows, static fn(array $row): bool => ($row['type'] ?? '') === 'post')),
			],
		];
	}

	private function getTechnicalData(array $auditRows): array
	{
		$settings = $this->loadSettings(self::TECHNICAL_DEFAULTS);
		$brokenLinkReport = $this->brokenLinkService->getAdminData();
		$redirectData = $this->redirectService->getAdminData();
		$indexNowStatus = $this->indexingService->getIndexNowConfigurationStatus();

		$missingAltRows = array_values(array_filter($auditRows, static function (array $row): bool {
			return (int)($row['analysis']['stats']['missing_alt_texts'] ?? 0) > 0;
		}));

		$noindexCandidates = array_values(array_filter($auditRows, static function (array $row): bool {
			return empty($row['robots_index']);
		}));

		$hreflangGroups = [];
		foreach ($auditRows as $row) {
			$group = trim((string)($row['hreflang_group'] ?? ''));
			if ($group === '') {
				continue;
			}
			$hreflangGroups[$group][] = [
				'type' => (string)($row['type'] ?? ''),
				'title' => (string)($row['title'] ?? ''),
				'slug' => (string)($row['slug'] ?? ''),
			];
		}

		return [
			'settings' => $settings,
			'broken_links' => (array)($brokenLinkReport['findings'] ?? []),
			'broken_links_report' => $brokenLinkReport,
			'missing_alt_rows' => array_slice($missingAltRows, 0, 10),
			'noindex_candidates' => array_slice($noindexCandidates, 0, 10),
			'hreflang_groups' => $hreflangGroups,
			'redirect_stats' => $redirectData['stats'] ?? [],
			'indexnow' => $indexNowStatus,
		];
	}

	private function buildOverview(array $auditRows): array
	{
		$scores = ['good' => 0, 'warning' => 0, 'bad' => 0];
		$totalScore = 0;

		foreach ($auditRows as &$row) {
			$analysis = (array)($row['analysis'] ?? []);
			$status = (string)($analysis['status'] ?? 'warning');
			if (!isset($scores[$status])) {
				$status = 'warning';
			}

			$row['seo_score'] = $status;
			$row['seo_score_value'] = (int)($analysis['score'] ?? 0);
			$row['seo_issues'] = array_values(array_map(static function (array $rule): array {
				return [
					'type' => !empty($rule['passed']) ? 'good' : 'warning',
					'msg' => (string)($rule['label'] ?? ''),
					'detail' => (string)($rule['message'] ?? ''),
				];
			}, array_filter((array)($analysis['rules'] ?? []), static fn(array $rule): bool => empty($rule['passed']))));
			$scores[$status]++;
			$totalScore += (int)($analysis['score'] ?? 0);
		}
		unset($row);

		$files = $this->getSitemapFilesStatus();
		$robotsStatus = $files['robots.txt'] ?? ['exists' => false, 'updated_at' => null];

		return [
			'content' => $auditRows,
			'scores' => $scores,
			'total' => count($auditRows),
			'average_score' => count($auditRows) > 0 ? (int)round($totalScore / count($auditRows)) : 0,
			'status' => [
				'sitemap_exists' => !empty($files['sitemap.xml']['exists']),
				'sitemap_date' => $files['sitemap.xml']['updated_at'] ?? null,
				'robots_exists' => !empty($robotsStatus['exists']),
				'robots_date' => $robotsStatus['updated_at'] ?? null,
				'files' => $files,
			],
		];
	}

	/**
	 * @return array{overview: array<string, mixed>, audit_rows: array<int, array<string, mixed>>}
	 */
	private function buildSectionContext(): array
	{
		// FIX: Audit-Zeilen immer zentral normalisieren, damit Views niemals auf rohe Datenstrukturen angewiesen sind.
		$auditRows = $this->normalizeAuditRows($this->analysisService->enrichAuditRows($this->seoService->getAuditRows()));
		$overview = $this->buildOverview($auditRows);

		return [
			'overview' => $overview,
			'audit_rows' => (array)($overview['content'] ?? $auditRows),
		];
	}

	private function getSitemapFilesStatus(): array
	{
		$result = [];
		foreach (['sitemap.xml', 'pages.xml', 'posts.xml', 'en-pages.xml', 'en-posts.xml', 'plugins.xml', 'images.xml', 'news.xml', 'robots.txt'] as $file) {
			$path = ABSPATH . $file;
			$exists = file_exists($path);
			$result[$file] = [
				'exists' => $exists,
				'path' => '/' . $file,
				'updated_at' => $exists ? date('d.m.Y H:i', (int)filemtime($path)) : null,
				'size' => $exists ? (int)filesize($path) : 0,
			];
		}

		return $result;
	}

	private function loadSettings(array $defaults): array
	{
		$settings = $defaults;
		if ($defaults === []) {
			return $settings;
		}

		$placeholders = implode(',', array_fill(0, count($defaults), '?'));
		$rows = $this->db->get_results(
			"SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
			array_keys($defaults)
		) ?: [];

		foreach ($rows as $row) {
			$name = (string)($row->option_name ?? '');
			if ($name === '' || !array_key_exists($name, $settings)) {
				continue;
			}
			$settings[$name] = (string)($row->option_value ?? $settings[$name]);
		}

		return $settings;
	}

	private function persistSettings(array $values): void
	{
		if ($values === []) {
			return;
		}

		$keys = array_map('strval', array_keys($values));
		$placeholders = implode(',', array_fill(0, count($keys), '?'));
		$existingRows = $this->db->get_results(
			"SELECT option_name FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
			$keys
		) ?: [];
		$existing = [];
		foreach ($existingRows as $row) {
			$name = (string)($row->option_name ?? '');
			if ($name !== '') {
				$existing[$name] = true;
			}
		}

		foreach ($values as $key => $value) {
			$exists = isset($existing[(string)$key]);

			if ($exists) {
				$this->db->execute(
					"UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ?",
					[(string)$value, (string)$key]
				);
				continue;
			}

			$this->db->execute(
				"INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)",
				[(string)$key, (string)$value]
			);
		}
	}


	private function normalizeOptionalUrl(string $value, bool $allowRelative): string
	{
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		if ($allowRelative && str_starts_with($value, '/')) {
			return '/' . ltrim($value, '/');
		}

		$sanitized = trim((string)filter_var($value, FILTER_SANITIZE_URL));
		if ($sanitized === '' || filter_var($sanitized, FILTER_VALIDATE_URL) === false) {
			return '';
		}

		$scheme = strtolower((string)parse_url($sanitized, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true)) {
			return '';
		}

		return $sanitized;
	}

	private function normalizeAllowedValue(string $value, array $allowed, string $fallback): string
	{
		$value = strtolower(trim($value));

		return in_array($value, $allowed, true) ? $value : $fallback;
	}

	private function normalizeTrackingId(string $value, string $pattern): string
	{
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		return preg_match($pattern, $value) === 1 ? $value : '';
	}

	private function normalizePositiveIntString(mixed $value, string $fallback): string
	{
		$number = (int)$value;

		return $number > 0 ? (string)$number : $fallback;
	}

	private function normalizeSitemapPriority(string $value, string $fallback): string
	{
		$value = trim(str_replace(',', '.', $value));
		if ($value === '') {
			return $fallback;
		}

		$priority = (float)$value;
		$priority = max(0.0, min(1.0, $priority));

		return number_format($priority, 1, '.', '');
	}

	private function normalizeSitemapChangefreq(string $value, string $fallback): string
	{
		$value = strtolower(trim($value));
		if ($value === '') {
			return $fallback;
		}

		return in_array($value, self::ALLOWED_CHANGEFREQ, true) ? $value : $fallback;
	}

	private function normalizeLanguageCode(string $value): string
	{
		$value = strtolower(trim($value));
		return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $value) === 1 ? $value : 'de';
	}

	/**
	 * @return list<string>
	 */
	private function parseSubmittedUrls(string $rawUrls): array
	{
		$lines = preg_split('/\r\n|\r|\n/', $rawUrls) ?: [];
		$urls = [];

		foreach ($lines as $line) {
			$url = $this->normalizeIndexingUrl($line);
			if ($url === '') {
				continue;
			}

			$urls[$url] = $url;
			if (count($urls) >= self::MAX_INDEXING_URLS) {
				break;
			}
		}

		return array_values($urls);
	}

	private function normalizeIndexingUrl(string $value): string
	{
		$url = $this->normalizeOptionalUrl($value, false);
		if ($url === '') {
			return '';
		}

		$urlAuthority = $this->normalizeComparableAuthority($url);
		if ($urlAuthority === '' || !in_array($urlAuthority, $this->getKnownSiteAuthorities(), true)) {
			return '';
		}

		return $url;
	}

	/**
	 * @return list<string>
	 */
	private function getKnownSiteAuthorities(): array
	{
		if ($this->knownSiteAuthorities !== null) {
			return $this->knownSiteAuthorities;
		}

		$authorities = [];
		$candidates = [];

		if (defined('SITE_URL')) {
			$candidates[] = (string)SITE_URL;
		}

		if (function_exists('cms_runtime_base_url')) {
			try {
				$candidates[] = (string)cms_runtime_base_url();
			} catch (\Throwable) {
				// Runtime-Basis-URL ist optional; SITE_URL bleibt Fallback.
			}
		}

		foreach ($candidates as $candidate) {
			$authority = $this->normalizeComparableAuthority($candidate);
			if ($authority === '') {
				continue;
			}

			$authorities[$authority] = true;
		}

		$this->knownSiteAuthorities = array_keys($authorities);

		return $this->knownSiteAuthorities;
	}

	private function normalizeComparableAuthority(string $url): string
	{
		$parts = parse_url(trim($url));
		if (!is_array($parts) || empty($parts['host'])) {
			return '';
		}

		$authority = strtolower((string)$parts['host']);
		$port = isset($parts['port']) ? (int)$parts['port'] : 0;
		if ($port > 0) {
			$authority .= ':' . $port;
		}

		return $authority;
	}

	private function extractSameSitePathFromUrl(string $url): string
	{
		$authority = $this->normalizeComparableAuthority($url);
		if ($authority === '' || !in_array($authority, $this->getKnownSiteAuthorities(), true)) {
			return '';
		}

		$path = (string)parse_url($url, PHP_URL_PATH);
		return trim($path) !== '' ? $path : '/';
	}

	private function normalizeAuditRows(array $auditRows): array
	{
		return array_map(static function (array $row): array {
			$analysis = (array)($row['analysis'] ?? []);
			$status = (string)($analysis['status'] ?? ($row['seo_score'] ?? 'warning'));
			if (!in_array($status, ['good', 'warning', 'bad'], true)) {
				$status = 'warning';
			}

			$row['seo_score'] = $status;
			$row['seo_score_value'] = (int)($analysis['score'] ?? ($row['seo_score_value'] ?? 0));
			$row['seo_issues'] = array_values($row['seo_issues'] ?? array_map(static function (array $rule): array {
				return [
					'type' => !empty($rule['passed']) ? 'good' : 'warning',
					'msg' => (string)($rule['label'] ?? ''),
					'detail' => (string)($rule['message'] ?? ''),
				];
			}, array_filter((array)($analysis['rules'] ?? []), static fn(array $rule): bool => empty($rule['passed']))));

			return $row;
		}, $auditRows);
	}

	private function sanitizeLogValue(string $value, int $maxLength = 240): string
	{
		$value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';

		return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
	}

	private function normalizeIndexNowKey(string $value): string
	{
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		return preg_match('/^[A-Za-z0-9_-]{8,128}$/', $value) === 1 ? $value : '';
	}

	/**
	 * @param list<string> $availableFiles
	 */
	private function normalizeIndexNowKeyFile(string $value, array $availableFiles): string
	{
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		if (preg_match('/^[A-Za-z0-9._-]+\.txt$/', $value) !== 1) {
			return '';
		}

		return in_array($value, $availableFiles, true) ? $value : '';
	}
}
