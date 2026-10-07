<?php
declare(strict_types=1);

/**
 * Legacy setup route.
 *
 * Universal Architecture is now the authoritative setup workflow.
 * Keep this path for bookmarks/backward compatibility.
 */
header('Location: universal_setup.php', true, 302);
exit;
