<?php
/**
 * Plugin Name:       Lite Loader for Tin Canny
 * Description:       Speeds up LearnDash sites that use Tin Canny with iSpring, Articulate or Captivate modules. The resume bookmarks those modules save every few seconds load only the plugins they need instead of the whole site.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lite-loader-for-tin-canny
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

final class LLFTC_Plugin {

	const VERSION     = '1.0.0';
	const OPTION      = 'llftc_settings';
	const LOADER_FILE = 'lite-loader-for-tin-canny-loader.php';
	const SIGNATURE   = 'LLFTC-LOADER-SIGNATURE';
	const PAGE        = 'lite-loader-for-tin-canny';

	/** Plugins that can never be left out: the request cannot be answered without them. */
	const REQUIRED_DIRS = array( 'tin-canny-learndash-reporting', 'sfwd-lms' );

	/** Object cache plugins kept by default, so the cache drop-in keeps working as usual. */
	const CACHE_DIRS = array( 'object-cache-pro', 'redis-cache', 'wp-redis', 'litespeed-cache', 'w3-total-cache' );

	/** Lightweight themes tried in order for lite requests. */
	const LIGHT_THEMES = array( 'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone' );

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_sync_loader' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_llftc_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_llftc_reinstall', array( __CLASS__, 'handle_reinstall' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'lite-loader-for-tin-canny', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/** Active plugins whose folder is in $dirs. */
	private static function active_in_dirs( array $dirs ) {
		$found = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( in_array( strtok( $plugin, '/' ), $dirs, true ) ) {
				$found[] = $plugin;
			}
		}
		return $found;
	}

	private static function required_plugins() {
		return self::active_in_dirs( self::REQUIRED_DIRS );
	}

	private static function default_theme() {
		foreach ( self::LIGHT_THEMES as $slug ) {
			if ( wp_get_theme( $slug )->exists() ) {
				return $slug;
			}
		}
		return '';
	}

	public static function get_settings() {
		$saved    = get_option( self::OPTION );
		$defaults = array(
			'enabled'  => true,
			'keep'     => array_merge( self::required_plugins(), self::active_in_dirs( self::CACHE_DIRS ) ),
			'required' => self::required_plugins(),
			'theme'    => self::default_theme(),
			'main'     => plugin_basename( __FILE__ ),
		);
		return is_array( $saved ) ? wp_parse_args( $saved, $defaults ) : $defaults;
	}

	/** Required plugins are always kept and always refreshed from what is active now. */
	private static function save_settings( array $settings ) {
		$required             = self::required_plugins();
		$settings['required'] = $required;
		$settings['keep']     = array_values( array_unique( array_merge( $required, (array) $settings['keep'] ) ) );
		$settings['main']     = plugin_basename( __FILE__ );
		update_option( self::OPTION, $settings, true );
	}

	/* ---------------------------------------------------------------------
	 * Loader file in wp-content/mu-plugins
	 * ------------------------------------------------------------------- */

	private static function loader_path() {
		return trailingslashit( WPMU_PLUGIN_DIR ) . self::LOADER_FILE;
	}

	private static function loader_source() {
		return plugin_dir_path( __FILE__ ) . 'mu/' . self::LOADER_FILE;
	}

	/** @return string 'ok' | 'missing' | 'outdated' | 'foreign' */
	public static function loader_status() {
		$path = self::loader_path();
		if ( ! file_exists( $path ) ) {
			return 'missing';
		}
		$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === strpos( $contents, self::SIGNATURE ) ) {
			return 'foreign';
		}
		return ( md5( $contents ) === md5_file( self::loader_source() ) ) ? 'ok' : 'outdated';
	}

	private static function install_loader() {
		if ( 'foreign' === self::loader_status() ) {
			return false; // Never overwrite a file we did not create.
		}
		if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return false;
		}
		return @copy( self::loader_source(), self::loader_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function remove_loader() {
		if ( in_array( self::loader_status(), array( 'ok', 'outdated' ), true ) ) {
			@unlink( self::loader_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	public static function activate() {
		self::save_settings( self::get_settings() );
		if ( ! self::install_loader() ) {
			set_transient( 'llftc_install_failed', 1, HOUR_IN_SECONDS );
		}
	}

	public static function deactivate() {
		self::remove_loader();
	}

	/** Keeps the loader in step after a plugin update or a manual deletion. */
	public static function maybe_sync_loader() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$status = self::loader_status();
		if ( 'missing' === $status || 'outdated' === $status ) {
			self::install_loader();
		}

		// Tin Canny or LearnDash activated or deactivated after this plugin: refresh the list.
		$settings = self::get_settings();
		if ( $settings['required'] !== self::required_plugins() ) {
			self::save_settings( $settings );
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------- */

	public static function action_links( $links ) {
		$url = admin_url( 'tools.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lite-loader-for-tin-canny' ) . '</a>' );
		return $links;
	}

	public static function admin_menu() {
		add_management_page(
			__( 'Lite Loader for Tin Canny', 'lite-loader-for-tin-canny' ),
			__( 'Tin Canny Lite Loader', 'lite-loader-for-tin-canny' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_transient( 'llftc_install_failed' ) || 'foreign' === self::loader_status() || 'missing' === self::loader_status() ) {
			delete_transient( 'llftc_install_failed' );
			$url = admin_url( 'tools.php?page=' . self::PAGE );
			echo '<div class="notice notice-error"><p>' .
				wp_kses_post(
					sprintf(
						/* translators: %s: settings page URL */
						__( '<strong>Lite Loader for Tin Canny</strong> could not install its loader in <code>wp-content/mu-plugins/</code>, so it is not active. <a href="%s">See how to fix it</a>.', 'lite-loader-for-tin-canny' ),
						esc_url( $url )
					)
				) . '</p></div>';
		}
		if ( count( self::required_plugins() ) < count( self::REQUIRED_DIRS ) ) {
			echo '<div class="notice notice-warning"><p>' .
				wp_kses_post( __( '<strong>Lite Loader for Tin Canny</strong> is idle: it needs both LearnDash and Tin Canny LearnDash Reporting to be active.', 'lite-loader-for-tin-canny' ) ) .
				'</p></div>';
		}
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lite-loader-for-tin-canny' ) );
		}
		check_admin_referer( 'llftc_save' );

		$active = (array) get_option( 'active_plugins', array() );
		$keep   = isset( $_POST['keep'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['keep'] ) ) : array();
		$theme  = isset( $_POST['theme'] ) ? sanitize_key( wp_unslash( $_POST['theme'] ) ) : '';

		if ( '' !== $theme && ! wp_get_theme( $theme )->exists() ) {
			$theme = '';
		}

		$settings            = self::get_settings();
		$settings['enabled'] = ! empty( $_POST['enabled'] );
		$settings['keep']    = array_values( array_intersect( $active, $keep ) );
		$settings['theme']   = $theme;
		self::save_settings( $settings );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'tools.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function handle_reinstall() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lite-loader-for-tin-canny' ) );
		}
		check_admin_referer( 'llftc_reinstall' );
		$ok = self::install_loader();
		wp_safe_redirect( add_query_arg( $ok ? 'reinstalled' : 'reinstall_failed', '1', admin_url( 'tools.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = self::get_settings();
		$required   = self::required_plugins();
		$status     = self::loader_status();
		$all        = get_plugins();
		$active     = (array) get_option( 'active_plugins', array() );
		$site_theme = wp_get_theme();

		$status_labels = array(
			'ok'       => array( 'success', __( 'Installed and up to date.', 'lite-loader-for-tin-canny' ) ),
			'outdated' => array( 'warning', __( 'Installed, but an older version. It is updated on the next admin page load.', 'lite-loader-for-tin-canny' ) ),
			'missing'  => array( 'error', __( 'Not installed. Lite mode is off.', 'lite-loader-for-tin-canny' ) ),
			'foreign'  => array( 'error', __( 'A file with the same name exists and was not created by this plugin. It was left untouched; lite mode is off.', 'lite-loader-for-tin-canny' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lite Loader for Tin Canny', 'lite-loader-for-tin-canny' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'lite-loader-for-tin-canny' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['reinstalled'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Loader reinstalled.', 'lite-loader-for-tin-canny' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['reinstall_failed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The loader could not be written. Check that the web server can write to wp-content/mu-plugins/.', 'lite-loader-for-tin-canny' ); ?></p></div>
			<?php endif; ?>

			<p style="max-width:760px">
				<?php esc_html_e( 'iSpring, Articulate and Captivate modules save the learner\'s resume bookmark to Tin Canny every few seconds. Each save normally loads every active plugin and the site theme. With lite mode, those requests load only the plugins below. Statements, completions and every other page on the site are not affected.', 'lite-loader-for-tin-canny' ); ?>
			</p>

			<h2><?php esc_html_e( 'Status', 'lite-loader-for-tin-canny' ); ?></h2>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr>
						<td style="width:220px"><strong><?php esc_html_e( 'Loader (mu-plugin)', 'lite-loader-for-tin-canny' ); ?></strong></td>
						<td>
							<span class="notice-<?php echo esc_attr( $status_labels[ $status ][0] ); ?>" style="border-left:4px solid;padding-left:8px;display:inline-block">
								<?php echo esc_html( $status_labels[ $status ][1] ); ?>
							</span>
							<br><code><?php echo esc_html( str_replace( ABSPATH, '', self::loader_path() ) ); ?></code>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Required plugins', 'lite-loader-for-tin-canny' ); ?></strong></td>
						<td>
							<?php
							if ( count( $required ) < count( self::REQUIRED_DIRS ) ) {
								esc_html_e( 'LearnDash and Tin Canny LearnDash Reporting must both be active. Lite mode is idle.', 'lite-loader-for-tin-canny' );
							} else {
								echo esc_html( implode( ', ', array_map( array( __CLASS__, 'plugin_name' ), $required ) ) );
							}
							?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Kill switch', 'lite-loader-for-tin-canny' ); ?></strong></td>
						<td>
							<?php if ( defined( 'LLFTC_DISABLE' ) && LLFTC_DISABLE ) : ?>
								<?php esc_html_e( 'LLFTC_DISABLE is defined: lite mode is off.', 'lite-loader-for-tin-canny' ); ?>
							<?php else : ?>
								<?php echo wp_kses_post( __( 'Add <code>define( \'LLFTC_DISABLE\', true );</code> to wp-config.php to turn lite mode off without wp-admin.', 'lite-loader-for-tin-canny' ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'How to check it works', 'lite-loader-for-tin-canny' ); ?></strong></td>
						<td><?php echo wp_kses_post( __( 'Open a module, then in the browser\'s developer tools (Network tab) select a <code>activities/state</code> request: its response headers include <code>X-Lite-Loader</code>.', 'lite-loader-for-tin-canny' ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( in_array( $status, array( 'missing', 'foreign' ), true ) ) : ?>
				<p style="max-width:760px">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: source file, 2: destination folder */
							__( 'To install it by hand, copy %1$s into %2$s.', 'lite-loader-for-tin-canny' ),
							'<code>' . esc_html( str_replace( ABSPATH, '', self::loader_source() ) ) . '</code>',
							'<code>' . esc_html( str_replace( ABSPATH, '', trailingslashit( WPMU_PLUGIN_DIR ) ) ) . '</code>'
						)
					);
					?>
				</p>
				<?php if ( 'missing' === $status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="llftc_reinstall">
						<?php wp_nonce_field( 'llftc_reinstall' ); ?>
						<?php submit_button( __( 'Try installing the loader again', 'lite-loader-for-tin-canny' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Settings', 'lite-loader-for-tin-canny' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="llftc_save">
				<?php wp_nonce_field( 'llftc_save' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Lite mode', 'lite-loader-for-tin-canny' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
								<?php esc_html_e( 'Load only the plugins below for Tin Canny state requests', 'lite-loader-for-tin-canny' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugins to load', 'lite-loader-for-tin-canny' ); ?></th>
						<td>
							<fieldset style="max-height:360px;overflow:auto;border:1px solid #dcdcde;padding:8px 12px;max-width:560px;background:#fff">
								<?php
								foreach ( $active as $plugin ) :
									if ( plugin_basename( __FILE__ ) === $plugin ) {
										continue;
									}
									$is_required = in_array( $plugin, $required, true );
									$is_checked  = $is_required || in_array( $plugin, (array) $settings['keep'], true );
									?>
									<label style="display:block;margin:4px 0">
										<input type="checkbox" name="keep[]" value="<?php echo esc_attr( $plugin ); ?>" <?php checked( $is_checked ); ?> <?php disabled( $is_required ); ?>>
										<?php echo esc_html( self::plugin_name( $plugin, $all ) ); ?>
										<?php if ( $is_required ) : ?>
											<em>(<?php esc_html_e( 'required', 'lite-loader-for-tin-canny' ); ?>)</em>
											<input type="hidden" name="keep[]" value="<?php echo esc_attr( $plugin ); ?>">
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description" style="max-width:560px">
								<?php esc_html_e( 'Keep this list short. Add a plugin only if it changes how Tin Canny or LearnDash read and save data (for example a plugin that hooks into Tin Canny\'s filters). Object cache plugins are kept by default.', 'lite-loader-for-tin-canny' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="llftc-theme"><?php esc_html_e( 'Theme for these requests', 'lite-loader-for-tin-canny' ); ?></label></th>
						<td>
							<select id="llftc-theme" name="theme">
								<option value="" <?php selected( '', $settings['theme'] ); ?>>
									<?php
									/* translators: %s: active theme name */
									echo esc_html( sprintf( __( 'Site theme (%s)', 'lite-loader-for-tin-canny' ), $site_theme->get( 'Name' ) ) );
									?>
								</option>
								<?php foreach ( wp_get_themes() as $slug => $theme ) : ?>
									<?php
									if ( $slug === $site_theme->get_stylesheet() ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $settings['theme'] ); ?>><?php echo esc_html( $theme->get( 'Name' ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" style="max-width:560px">
								<?php esc_html_e( 'Nothing is displayed in these requests, but WordPress still runs the theme\'s functions.php. A default theme (Twenty Twenty-Five or similar) is the lightest choice and avoids errors from themes that depend on plugins that are not loaded. It only needs to be installed, not active.', 'lite-loader-for-tin-canny' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function plugin_name( $plugin, $all = null ) {
		if ( null === $all ) {
			$all = get_plugins();
		}
		return isset( $all[ $plugin ]['Name'] ) ? $all[ $plugin ]['Name'] : $plugin;
	}
}

LLFTC_Plugin::init();
