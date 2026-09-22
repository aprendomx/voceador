<?php
/**
 * Desinstalación de Voceador.
 *
 * @package Voceador
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/bootstrap.php';

\Voceador\Uninstaller::run();
