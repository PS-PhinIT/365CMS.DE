<?php
declare(strict_types=1);

/**
 * AnalyticsModule – Interne Besucherstatistiken (ohne externen Service)
 */

if (!defined('ABSPATH')) {
    exit;
}

class AnalyticsModule
{
    private readonly \CMS\Database $db;
    private readonly string $prefix;

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function getData(): array
    {
        $hasTable = $this->db->tableExists($this->prefix . 'page_views');

        $stats = [
            'today'      => 0,
            'yesterday'  => 0,
            'week'       => 0,
            'month'      => 0,
            'total'      => 0,
        ];
        $topPages  = [];
        $topPosts  = [];
        $daily     = [];
        $referrers = [];

        if ($hasTable) {
            $stats['today'] = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}page_views WHERE DATE(visited_at) = CURDATE()"
            );
            $stats['yesterday'] = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}page_views WHERE DATE(visited_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
            );
            $stats['week'] = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}page_views WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $stats['month'] = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}page_views WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            $stats['total'] = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}page_views");

            // Top-Seiten
            $topPages = $this->db->get_results(
                "SELECT page_slug, COUNT(*) as views FROM {$this->prefix}page_views
                 WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY page_slug ORDER BY views DESC LIMIT 10"
            ) ?: [];

            // Tägliche Views (letzte 30 Tage)
            $daily = $this->db->get_results(
                "SELECT DATE(visited_at) as day, COUNT(*) as views FROM {$this->prefix}page_views
                 WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY DATE(visited_at) ORDER BY day ASC"
            ) ?: [];

            // Top-Referrer
            $referrers = $this->db->get_results(
                "SELECT referrer, COUNT(*) as cnt FROM {$this->prefix}page_views
                 WHERE referrer IS NOT NULL AND referrer != '' AND visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY referrer ORDER BY cnt DESC LIMIT 10"
            ) ?: [];
        }

        // Content-Stats
        $contentStats = [
            'pages'    => (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}pages WHERE status = 'published'"),
            'posts'    => (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}posts p WHERE " . \cms_post_publication_where('p')),
            'comments' => (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}comments") ?: 0,
            'users'    => (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}users"),
        ];

        // GA4-ID aus SEO › Analytics (das frühere Feld „google_analytics“ der allgemeinen Einstellungen wurde nie ausgegeben).
        $gaId = $this->db->get_row("SELECT option_value FROM {$this->prefix}settings WHERE option_name = 'seo_analytics_ga4_id'");

        return [
            'stats'         => $stats,
            'top_pages'     => array_map(fn($r) => (array)$r, $topPages),
            'daily'         => array_map(fn($r) => (array)$r, $daily),
            'referrers'     => array_map(fn($r) => (array)$r, $referrers),
            'content_stats' => $contentStats,
            'ga_id'         => $gaId->option_value ?? '',
            'has_table'     => $hasTable,
        ];
    }
}
