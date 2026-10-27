<?php
/**
 * Runs when the plugin is deleted from wp-admin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'llftc_settings' );
delete_transient( 'llftc_install_failed' );

// The loader is normally removed on deactivation; remove it here too in case
// the plugin was deleted without being deactivated first. Only our own file.
$llftc_loader = trailingslashit( WPMU_PLUGIN_DIR ) . 'lite-loader-for-tin-canny-loader.php';
if ( file_exists( $llftc_loader ) && false !== strpos( (string) file_get_contents( $llftc_loader ), 'LLFTC-LOADER-SIGNATURE' ) ) { // phpcs:ignore
	@unlink( $llftc_loader ); // phpcs:ignore
}
