<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Einstiegspunkt (structure). Route: /admin/community-structure
 * Logik und Ansicht: admin/modules/community/, admin/views/community/.
 */

use CMS\Auth;

const CMS_COMMUNITY_ADMIN_SECTION = 'structure';

function cms_admin_community_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability('community.manage');
}

if (!cms_admin_community_can_access()) {
    header('Location: /');
    exit;
}

require __DIR__ . '/modules/community/entry.php';
