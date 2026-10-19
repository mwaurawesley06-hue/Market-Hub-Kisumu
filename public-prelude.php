<?php
declare(strict_types=1);

/**
 * Tiny prelude included at the very top of each public HTML page (via PHP) so
 * the markup can use the administrator-configured branding. Emits nothing.
 *
 * The public pages live at the app root, so this file is includeable as
 * public-prelude.php from those pages. Older copies that included it as
 * public_prelude.php are still supported.
 */
require_once __DIR__ . '/api/includes/public-branding.php';
$branding = public_branding();
