<?php
/**
 * Admin pages: Endpoints, Deliveries, Settings.
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
final class NTWH_Admin {

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . NTWH_BASENAME, array( __CLASS__, 'action_links' ) );
		foreach ( array( 'save_endpoint', 'delete_endpoint', 'toggle_endpoint', 'test_endpoint', 'resend', 'save_settings' ) as $a ) {
			add_action( 'admin_post_ntwh_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * Capability for every page and action.
	 *
	 * @return string
	 */
	public static function cap() {
		return (string) apply_filters( 'ntwh_capability', 'manage_options' );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( 'ntwh_settings', array() );
		// Pro adds its own keys (alerts, retention, inbound) with their defaults.
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		$cap = self::cap();
		add_menu_page( __( 'NodeThemes Webhooks', 'nodethemes-webhooks' ), __( 'NT Webhooks', 'nodethemes-webhooks' ), $cap, 'ntwh', array( __CLASS__, 'page_endpoints' ), 'dashicons-share-alt2', 58 );
		add_submenu_page( 'ntwh', __( 'Endpoints', 'nodethemes-webhooks' ), __( 'Endpoints', 'nodethemes-webhooks' ), $cap, 'ntwh', array( __CLASS__, 'page_endpoints' ) );
		add_submenu_page( 'ntwh', __( 'Deliveries', 'nodethemes-webhooks' ), __( 'Deliveries', 'nodethemes-webhooks' ), $cap, 'ntwh-deliveries', array( __CLASS__, 'page_deliveries' ) );
		add_submenu_page( 'ntwh', __( 'Settings', 'nodethemes-webhooks' ), __( 'Settings & Help', 'nodethemes-webhooks' ), $cap, 'ntwh-settings', array( __CLASS__, 'page_settings' ) );
		do_action( 'ntwh_admin_menu', $cap );
	}

	/**
	 * Is the current screen one of ours?
	 *
	 * @return bool
	 */
	public static function is_our_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return 0 === strpos( $page, 'ntwh' );
	}

	/**
	 * Assets on our pages.
	 */
	public static function assets() {
		if ( ! self::is_our_screen() ) {
			return;
		}
		wp_enqueue_style( 'ntwh-admin', NTWH_URL . 'assets/admin.css', array(), NTWH_VERSION );
		wp_enqueue_script( 'ntwh-admin', NTWH_URL . 'assets/admin.js', array(), NTWH_VERSION, true );
		wp_localize_script(
			'ntwh-admin',
			'ntwhAdmin',
			array(
				'copied'  => __( 'Copied', 'nodethemes-webhooks' ),
				'confirm' => __( 'Delete this endpoint? Its delivery log is kept.', 'nodethemes-webhooks' ),
			)
		);
	}

	/**
	 * Plugin list links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=ntwh' ) ) . '">' . esc_html__( 'Endpoints', 'nodethemes-webhooks' ) . '</a>' );
		if ( ! NTWH_Pro::has_code() ) {
			$links[] = '<a href="' . esc_url( NTWH_Pro::url( 'plugins' ) ) . '" target="_blank" rel="noopener" style="font-weight:600">' . esc_html__( 'Get Pro', 'nodethemes-webhooks' ) . '</a>';
		}
		return $links;
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	/**
	 * Warn when an endpoint keeps failing or is paused.
	 */
	public static function notices() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$screen = get_current_screen();
		$where  = array( 'dashboard', 'plugins', 'woocommerce_page_wc-orders', 'edit-shop_order' );
		if ( ! $screen || ( ! in_array( $screen->id, $where, true ) && ! self::is_our_screen() ) ) {
			return;
		}
		foreach ( NTWH_Endpoints::all() as $ep ) {
			if ( ! $ep['active'] ) {
				continue;
			}
			if ( NTWH_Endpoints::paused_for_pro( $ep ) ) {
				printf(
					'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
					esc_html( sprintf( /* translators: %s: endpoint name */ __( 'Webhook endpoint "%s" is paused.', 'nodethemes-webhooks' ), $ep['name'] ) ),
					esc_html__( 'It uses Pro settings (conditions, custom payload or headers) and Pro is not active, so nothing is sent to it.', 'nodethemes-webhooks' ),
					esc_url( NTWH_Pro::has_code() ? NTWH_Pro::license_url() : admin_url( 'admin.php?page=ntwh&edit=' . rawurlencode( $ep['id'] ) ) ),
					esc_html( NTWH_Pro::has_code() ? __( 'Check the license', 'nodethemes-webhooks' ) : __( 'Edit the endpoint', 'nodethemes-webhooks' ) )
				);
				continue;
			}
			$h = NTWH_Endpoints::health( $ep['id'] );
			if ( (int) $h['fails'] >= 3 ) {
				printf(
					'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
					esc_html( sprintf( /* translators: 1: endpoint name, 2: time */ __( 'Webhook endpoint "%1$s" has been failing for %2$s.', 'nodethemes-webhooks' ), $ep['name'], human_time_diff( (int) $h['down_since'] ) ) ),
					esc_html( sprintf( /* translators: %s: error */ __( 'Last error: %s. Deliveries are kept and retried; failed ones can be resent from the log.', 'nodethemes-webhooks' ), rtrim( (string) $h['last_error'], '. ' ) ) ),
					esc_url( admin_url( 'admin.php?page=ntwh-deliveries&endpoint_id=' . rawurlencode( $ep['id'] ) ) ),
					esc_html__( 'Open the delivery log', 'nodethemes-webhooks' )
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Flash message.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 */
	public static function flash( $type, $message ) {
		set_transient( 'ntwh_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	/**
	 * Print and clear the flash message.
	 */
	public static function print_flash() {
		$k = 'ntwh_flash_' . get_current_user_id();
		$v = get_transient( $k );
		if ( is_array( $v ) ) {
			delete_transient( $k );
			echo '<div class="notice notice-' . esc_attr( $v[0] ) . ' is-dismissible"><p>' . esc_html( $v[1] ) . '</p></div>';
		}
	}

	/**
	 * Guard for handlers.
	 *
	 * @param string $nonce Nonce action.
	 */
	private static function guard( $nonce ) {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nodethemes-webhooks' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Redirect to one of our pages.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Query args.
	 */
	private static function go( $page, $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Local time label for a UTC datetime.
	 *
	 * @param string|null $utc UTC datetime.
	 * @return string
	 */
	public static function when( $utc ) {
		if ( empty( $utc ) ) {
			return '—';
		}
		$ts = strtotime( $utc . ' UTC' );
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Status badge.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function badge( $status ) {
		$labels = array(
			'queued'   => __( 'Queued', 'nodethemes-webhooks' ),
			'sending'  => __( 'Sending', 'nodethemes-webhooks' ),
			'retrying' => __( 'Will retry', 'nodethemes-webhooks' ),
			'success'  => __( 'Delivered', 'nodethemes-webhooks' ),
			'failed'   => __( 'Failed', 'nodethemes-webhooks' ),
			'skipped'  => __( 'Skipped', 'nodethemes-webhooks' ),
			'resent'   => __( 'Resent', 'nodethemes-webhooks' ),
		);
		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="ntwh-badge is-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Page header.
	 *
	 * @param string $title Title.
	 * @param string $sub   Subtitle.
	 */
	public static function header( $title, $sub = '' ) {
		echo '<header class="ntwh-header"><div><h1>' . esc_html( $title );
		if ( NTWH_Pro::active() ) {
			echo ' <span class="ntwh-protag on">PRO</span>';
		}
		echo '</h1>';
		if ( $sub ) {
			echo '<p>' . esc_html( $sub ) . '</p>';
		}
		echo '</div><span class="ntwh-version">v' . esc_html( NTWH_VERSION ) . '</span></header><hr class="wp-header-end">';
		self::print_flash();
	}

	/**
	 * Support box (same contacts on every NodeThemes product).
	 */
	public static function support_box() {
		?>
		<div class="ntwh-card ntwh-support">
			<strong><?php esc_html_e( 'Need help?', 'nodethemes-webhooks' ); ?></strong>
			<?php esc_html_e( 'Email', 'nodethemes-webhooks' ); ?> <a href="mailto:nodethemes.com@gmail.com">nodethemes.com@gmail.com</a>
			&middot; WhatsApp <a href="https://wa.me/447441429969" target="_blank" rel="noopener">+44 7441 429969</a>
			&middot; Telegram <a href="https://t.me/nodethemes" target="_blank" rel="noopener">@nodethemes</a>
			&middot; <a href="https://nodethemes.com/" target="_blank" rel="noopener">nodethemes.com</a>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Endpoints page
	 * ------------------------------------------------------------------ */

	/**
	 * Endpoints list or edit form.
	 */
	public static function page_endpoints() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$new  = ! empty( $_GET['new'] );
		// phpcs:enable
		echo '<div class="wrap ntwh-wrap">';
		if ( $new || ( '' !== $edit && NTWH_Endpoints::get( $edit ) ) ) {
			self::endpoint_form( $new ? null : NTWH_Endpoints::get( $edit ) );
		} else {
			self::endpoint_list();
		}
		echo '</div>';
	}

	/**
	 * List of endpoints.
	 */
	private static function endpoint_list() {
		$all    = NTWH_Endpoints::all();
		$counts = NTWH_DB::status_counts( 24 );
		$max    = NTWH_Endpoints::max();
		self::header( __( 'NodeThemes Webhooks', 'nodethemes-webhooks' ), __( 'Send WooCommerce events to n8n, Make, Zapier or any URL — with retries, signatures and a full delivery log.', 'nodethemes-webhooks' ) );
		?>
		<div class="ntwh-stats">
			<?php
			$stats = array(
				'success'  => __( 'Delivered (24h)', 'nodethemes-webhooks' ),
				'retrying' => __( 'Waiting to retry', 'nodethemes-webhooks' ),
				'failed'   => __( 'Failed (24h)', 'nodethemes-webhooks' ),
				'skipped'  => __( 'Skipped (24h)', 'nodethemes-webhooks' ),
			);
			foreach ( $stats as $k => $label ) {
				$n = isset( $counts[ $k ] ) ? $counts[ $k ] : 0;
				if ( 'retrying' === $k ) {
					$n += ( isset( $counts['queued'] ) ? $counts['queued'] : 0 ) + ( isset( $counts['sending'] ) ? $counts['sending'] : 0 );
				}
				echo '<a class="ntwh-stat is-' . esc_attr( $k ) . '" href="' . esc_url( admin_url( 'admin.php?page=ntwh-deliveries&status=' . $k ) ) . '"><strong>' . esc_html( number_format_i18n( $n ) ) . '</strong><span>' . esc_html( $label ) . '</span></a>';
			}
			?>
		</div>

		<div class="ntwh-card">
			<div class="ntwh-card-head">
				<h2><?php esc_html_e( 'Endpoints', 'nodethemes-webhooks' ); ?></h2>
				<?php
				if ( count( $all ) < $max ) {
					echo '<a class="ntwh-btn primary" href="' . esc_url( admin_url( 'admin.php?page=ntwh&new=1' ) ) . '">+ ' . esc_html__( 'Add endpoint', 'nodethemes-webhooks' ) . '</a>';
				} else {
					echo NTWH_Pro::lock( __( 'Add endpoint', 'nodethemes-webhooks' ), 'endpoints' ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</div>
			<?php if ( ! $all ) : ?>
				<div class="ntwh-empty">
					<p><strong><?php esc_html_e( 'No endpoints yet.', 'nodethemes-webhooks' ); ?></strong></p>
					<ol>
						<li><?php esc_html_e( 'In n8n add a "Webhook" node (method POST) — or in Make a "Custom webhook", or in Zapier "Catch Hook" — and copy its production URL.', 'nodethemes-webhooks' ); ?></li>
						<li><?php esc_html_e( 'Click "Add endpoint", paste the URL and choose the events.', 'nodethemes-webhooks' ); ?></li>
						<li><?php esc_html_e( 'Press "Send test" to check the connection. Real orders are then sent automatically.', 'nodethemes-webhooks' ); ?></li>
					</ol>
				</div>
			<?php else : ?>
				<div class="ntwh-endpoints">
				<?php
				foreach ( $all as $ep ) {
					self::endpoint_row( $ep );
				}
				?>
				</div>
				<?php if ( ! NTWH_Pro::active() ) : ?>
					<p class="ntwh-help">
						<?php
						/* translators: 1: used, 2: allowed */
						echo esc_html( sprintf( __( '%1$d of %2$d endpoints used in the free version.', 'nodethemes-webhooks' ), count( $all ), $max ) );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		if ( ! NTWH_Pro::active() ) {
			NTWH_Pro::upsell_box( __( 'Do more with Pro', 'nodethemes-webhooks' ) );
		}
		self::support_box();
	}

	/**
	 * One endpoint card.
	 *
	 * @param array $ep Endpoint.
	 */
	private static function endpoint_row( $ep ) {
		$h      = NTWH_Endpoints::health( $ep['id'] );
		$post   = admin_url( 'admin-post.php' );
		if ( ! $ep['active'] ) {
			$health = '<span class="ntwh-dot off"></span>' . esc_html__( 'Switched off', 'nodethemes-webhooks' );
		} elseif ( NTWH_Endpoints::paused_for_pro( $ep ) ) {
			$health = '<span class="ntwh-dot warn"></span>' . esc_html__( 'Paused — needs Pro', 'nodethemes-webhooks' );
		} elseif ( (int) $h['fails'] > 0 ) {
			/* translators: 1: number of errors, 2: time */
			$health = '<span class="ntwh-dot bad"></span>' . esc_html( sprintf( __( 'Failing: %1$d error(s) in a row, since %2$s ago', 'nodethemes-webhooks' ), (int) $h['fails'], human_time_diff( (int) $h['down_since'] ) ) );
		} elseif ( $h['last_success'] ) {
			/* translators: %s: time */
			$health = '<span class="ntwh-dot ok"></span>' . esc_html( sprintf( __( 'Healthy — last delivery %s ago', 'nodethemes-webhooks' ), human_time_diff( (int) $h['last_success'] ) ) );
		} else {
			$health = '<span class="ntwh-dot"></span>' . esc_html__( 'No deliveries yet — press "Send test"', 'nodethemes-webhooks' );
		}
		$labels = (array) $ep['events'];
		?>
		<div class="ntwh-endpoint<?php echo $ep['active'] ? '' : ' is-off'; ?>">
			<div class="ntwh-endpoint-main">
				<h3><a href="<?php echo esc_url( admin_url( 'admin.php?page=ntwh&edit=' . rawurlencode( $ep['id'] ) ) ); ?>"><?php echo esc_html( $ep['name'] ); ?></a></h3>
				<code class="ntwh-url"><?php echo esc_html( $ep['url'] ); ?></code>
				<p class="ntwh-health"><?php echo $health; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></p>
				<p class="ntwh-events"><?php foreach ( $labels as $l ) { echo '<span>' . esc_html( $l ) . '</span>'; } ?></p>
			</div>
			<div class="ntwh-endpoint-actions">
				<form method="post" action="<?php echo esc_url( $post ); ?>">
					<?php wp_nonce_field( 'ntwh_endpoint_' . $ep['id'] ); ?>
					<input type="hidden" name="action" value="ntwh_test_endpoint">
					<input type="hidden" name="id" value="<?php echo esc_attr( $ep['id'] ); ?>">
					<button class="ntwh-btn" type="submit"><?php esc_html_e( 'Send test', 'nodethemes-webhooks' ); ?></button>
				</form>
				<a class="ntwh-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=ntwh&edit=' . rawurlencode( $ep['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'nodethemes-webhooks' ); ?></a>
				<a class="ntwh-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=ntwh-deliveries&endpoint_id=' . rawurlencode( $ep['id'] ) ) ); ?>"><?php esc_html_e( 'Log', 'nodethemes-webhooks' ); ?></a>
				<form method="post" action="<?php echo esc_url( $post ); ?>">
					<?php wp_nonce_field( 'ntwh_endpoint_' . $ep['id'] ); ?>
					<input type="hidden" name="action" value="ntwh_toggle_endpoint">
					<input type="hidden" name="id" value="<?php echo esc_attr( $ep['id'] ); ?>">
					<button class="ntwh-btn" type="submit"><?php echo $ep['active'] ? esc_html__( 'Switch off', 'nodethemes-webhooks' ) : esc_html__( 'Switch on', 'nodethemes-webhooks' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Add / edit form.
	 *
	 * @param array|null $ep Endpoint or null for new.
	 */
	private static function endpoint_form( $ep ) {
		$is_new = null === $ep;
		if ( $is_new && count( NTWH_Endpoints::all() ) >= NTWH_Endpoints::max() ) {
			self::header( __( 'Add endpoint', 'nodethemes-webhooks' ) );
			NTWH_Pro::upsell_box( __( 'Endpoint limit reached', 'nodethemes-webhooks' ), sprintf( /* translators: %d: number */ __( 'The free version supports %d endpoints.', 'nodethemes-webhooks' ), NTWH_Endpoints::max() ) );
			return;
		}
		if ( $is_new ) {
			$ep           = NTWH_Endpoints::defaults();
			$ep['secret'] = NTWH_Endpoints::new_secret();
			$ep['events'] = array( 'order.paid' );
		}
		$draft = get_transient( 'ntwh_form_' . get_current_user_id() );
		if ( is_array( $draft ) ) {
			delete_transient( 'ntwh_form_' . get_current_user_id() );
			$ep = array_merge( $ep, array_intersect_key( $draft, $ep ) );
		}
		$events = NTWH_Endpoints::events();
		self::header( $is_new ? __( 'Add endpoint', 'nodethemes-webhooks' ) : __( 'Edit endpoint', 'nodethemes-webhooks' ), $is_new ? '' : $ep['name'] );
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ntwh' ) ); ?>">&larr; <?php esc_html_e( 'All endpoints', 'nodethemes-webhooks' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ntwh-form">
			<?php wp_nonce_field( 'ntwh_save_endpoint' ); ?>
			<input type="hidden" name="action" value="ntwh_save_endpoint">
			<input type="hidden" name="ep[id]" value="<?php echo esc_attr( $is_new ? '' : $ep['id'] ); ?>">

			<div class="ntwh-card">
				<h2><?php esc_html_e( 'Where to send', 'nodethemes-webhooks' ); ?></h2>
				<div class="ntwh-field">
					<label for="ntwh-name"><?php esc_html_e( 'Name', 'nodethemes-webhooks' ); ?></label>
					<input type="text" id="ntwh-name" name="ep[name]" value="<?php echo esc_attr( $ep['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. n8n — paid orders', 'nodethemes-webhooks' ); ?>" maxlength="80">
				</div>
				<div class="ntwh-field">
					<label for="ntwh-url"><?php esc_html_e( 'Webhook URL', 'nodethemes-webhooks' ); ?> <span class="req">*</span></label>
					<input type="url" id="ntwh-url" name="ep[url]" value="<?php echo esc_attr( $ep['url'] ); ?>" placeholder="https://your-n8n.example.com/webhook/…" required>
					<p class="ntwh-help"><?php esc_html_e( 'Use the production URL of your n8n Webhook node, Make custom webhook or Zapier Catch Hook. The n8n "test URL" only works while the editor is listening.', 'nodethemes-webhooks' ); ?></p>
				</div>
				<label class="ntwh-switch"><input type="checkbox" name="ep[active]" value="1" <?php checked( $ep['active'] ); ?>> <?php esc_html_e( 'Endpoint is on', 'nodethemes-webhooks' ); ?></label>
			</div>

			<div class="ntwh-card">
				<h2><?php esc_html_e( 'Events', 'nodethemes-webhooks' ); ?></h2>
				<div class="ntwh-checks">
					<?php
					foreach ( $events as $key => $e ) {
						$available = NTWH_Endpoints::event_available( $key );
						$checked   = in_array( $key, (array) $ep['events'], true );
						echo '<label class="ntwh-check' . ( $available ? '' : ' is-locked' ) . '"><input type="checkbox" name="ep[events][]" value="' . esc_attr( $key ) . '" ' . checked( $checked, true, false ) . disabled( ! $available, true, false ) . '> <span><strong>' . esc_html( $e[0] ) . '</strong> <code>' . esc_html( $key ) . '</code>' . ( $e[2] ? ' ' . NTWH_Pro::badge() : '' ) . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</div>
				<p class="ntwh-help"><?php esc_html_e( '"Order paid" is sent once per order when it reaches a paid status (processing or completed) — the safest choice for invoices, SMS and fulfilment.', 'nodethemes-webhooks' ); ?></p>
			</div>

			<div class="ntwh-card">
				<h2><?php esc_html_e( 'Security', 'nodethemes-webhooks' ); ?></h2>
				<div class="ntwh-field">
					<label for="ntwh-secret"><?php esc_html_e( 'Signing secret', 'nodethemes-webhooks' ); ?></label>
					<div class="ntwh-inline">
						<input type="text" id="ntwh-secret" name="ep[secret]" value="<?php echo esc_attr( $ep['secret'] ); ?>" readonly class="code">
						<button type="button" class="ntwh-btn" data-ntwh-copy="#ntwh-secret"><?php esc_html_e( 'Copy', 'nodethemes-webhooks' ); ?></button>
					</div>
					<label class="ntwh-small"><input type="checkbox" name="ep[regenerate]" value="1"> <?php esc_html_e( 'Create a new secret when saving (the old one stops working)', 'nodethemes-webhooks' ); ?></label>
					<p class="ntwh-help">
						<?php
						printf(
							/* translators: %s: header names */
							esc_html__( 'Every request carries %s. The signature is HMAC-SHA256 of "timestamp.body" with this secret. See Settings & Help for ready-to-paste n8n / PHP / Node.js checks.', 'nodethemes-webhooks' ),
							'<code>X-NT-Timestamp</code> + <code>X-NT-Signature</code>'
						);
						?>
					</p>
				</div>
				<div class="ntwh-field">
					<label><?php esc_html_e( 'Authentication header (optional)', 'nodethemes-webhooks' ); ?></label>
					<div class="ntwh-inline">
						<input type="text" name="ep[auth_name]" value="<?php echo esc_attr( $ep['auth_name'] ); ?>" placeholder="X-Api-Key" aria-label="<?php esc_attr_e( 'Header name', 'nodethemes-webhooks' ); ?>">
						<input type="text" name="ep[auth_value]" value="<?php echo esc_attr( $ep['auth_value'] ); ?>" placeholder="<?php esc_attr_e( 'value', 'nodethemes-webhooks' ); ?>" aria-label="<?php esc_attr_e( 'Header value', 'nodethemes-webhooks' ); ?>" autocomplete="off">
					</div>
					<p class="ntwh-help"><?php esc_html_e( 'For n8n "Header Auth": use the same header name and value as in your n8n credential. Requests without it are rejected by n8n.', 'nodethemes-webhooks' ); ?></p>
				</div>
			</div>

			<?php
			if ( NTWH_Pro::active() ) {
				do_action( 'ntwh_endpoint_form_pro', $ep );
			} else {
				echo '<div class="ntwh-card ntwh-pro-preview"><h2>' . esc_html__( 'Conditions, custom payload & extra headers', 'nodethemes-webhooks' ) . ' ' . NTWH_Pro::badge() . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<p class="ntwh-help">' . esc_html__( 'Send only matching orders (e.g. total over 100, paid by card, shipped to Germany, contains a product), shape the JSON exactly as your workflow needs it, and add any extra headers.', 'nodethemes-webhooks' ) . '</p>';
				if ( NTWH_Endpoints::uses_pro( $ep ) && ( ! empty( $ep['conditions'] ) || 'custom' === $ep['payload_mode'] || ! empty( $ep['headers'] ) ) ) {
					echo '<p class="ntwh-warn">' . esc_html__( 'This endpoint has Pro settings saved. They are kept, but the endpoint is paused until Pro is active again.', 'nodethemes-webhooks' ) . '</p>';
				}
				echo '<p>' . NTWH_Pro::lock( __( 'Unlock conditions & payload builder', 'nodethemes-webhooks' ), 'conditions' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>

			<p class="ntwh-submit">
				<button type="submit" class="ntwh-btn primary lg"><?php echo $is_new ? esc_html__( 'Add endpoint', 'nodethemes-webhooks' ) : esc_html__( 'Save changes', 'nodethemes-webhooks' ); ?></button>
			</p>
		</form>

		<?php if ( ! $is_new ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ntwh-danger" data-ntwh-confirm>
				<?php wp_nonce_field( 'ntwh_endpoint_' . $ep['id'] ); ?>
				<input type="hidden" name="action" value="ntwh_delete_endpoint">
				<input type="hidden" name="id" value="<?php echo esc_attr( $ep['id'] ); ?>">
				<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete this endpoint', 'nodethemes-webhooks' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
		self::support_box();
	}

	/* ---------------------------------------------------------------------
	 * Deliveries page
	 * ------------------------------------------------------------------ */

	/**
	 * Delivery log.
	 */
	public static function page_deliveries() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$f = array(
			'status'      => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'event'       => isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '',
			'endpoint_id' => isset( $_GET['endpoint_id'] ) ? sanitize_key( wp_unslash( $_GET['endpoint_id'] ) ) : '',
			'object_id'   => isset( $_GET['object_id'] ) ? absint( $_GET['object_id'] ) : 0,
			'page'        => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
			'per_page'    => 50,
		);
		$open = isset( $_GET['open'] ) ? absint( $_GET['open'] ) : 0;
		// phpcs:enable
		list( $rows, $total ) = NTWH_DB::query( $f );
		$endpoints = NTWH_Endpoints::all();
		$events    = NTWH_Endpoints::events();
		$pages     = max( 1, (int) ceil( $total / $f['per_page'] ) );
		$days      = (int) apply_filters( 'ntwh_log_retention_days', NTWH_Pro::FREE_RETENTION );

		echo '<div class="wrap ntwh-wrap">';
		/* translators: %d: days */
		self::header( __( 'Deliveries', 'nodethemes-webhooks' ), sprintf( __( 'Every webhook sent in the last %d days, with the exact body, the answer and any error.', 'nodethemes-webhooks' ), $days ) );
		?>
		<form method="get" class="ntwh-filters">
			<input type="hidden" name="page" value="ntwh-deliveries">
			<label class="screen-reader-text" for="ntwh-f-status"><?php esc_html_e( 'Status', 'nodethemes-webhooks' ); ?></label>
			<select name="status" id="ntwh-f-status">
				<option value=""><?php esc_html_e( 'All statuses', 'nodethemes-webhooks' ); ?></option>
				<?php foreach ( array( 'success', 'failed', 'retrying', 'queued', 'sending', 'skipped', 'resent' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f['status'], $s ); ?>><?php echo wp_strip_all_tags( self::badge( $s ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ntwh-f-event"><?php esc_html_e( 'Event', 'nodethemes-webhooks' ); ?></label>
			<select name="event" id="ntwh-f-event">
				<option value=""><?php esc_html_e( 'All events', 'nodethemes-webhooks' ); ?></option>
				<?php foreach ( array_merge( array_keys( $events ), array( 'test.ping' ) ) as $e ) : ?>
					<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $f['event'], $e ); ?>><?php echo esc_html( $e ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ntwh-f-ep"><?php esc_html_e( 'Endpoint', 'nodethemes-webhooks' ); ?></label>
			<select name="endpoint_id" id="ntwh-f-ep">
				<option value=""><?php esc_html_e( 'All endpoints', 'nodethemes-webhooks' ); ?></option>
				<?php foreach ( $endpoints as $ep ) : ?>
					<option value="<?php echo esc_attr( $ep['id'] ); ?>" <?php selected( $f['endpoint_id'], $ep['id'] ); ?>><?php echo esc_html( $ep['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ntwh-f-obj"><?php esc_html_e( 'Order ID', 'nodethemes-webhooks' ); ?></label>
			<input type="number" min="1" name="object_id" id="ntwh-f-obj" value="<?php echo $f['object_id'] ? esc_attr( $f['object_id'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'Order / object ID', 'nodethemes-webhooks' ); ?>">
			<button class="ntwh-btn" type="submit"><?php esc_html_e( 'Filter', 'nodethemes-webhooks' ); ?></button>
		</form>

		<?php do_action( 'ntwh_deliveries_toolbar', $f ); ?>
		<?php if ( ! NTWH_Pro::active() ) : ?>
			<p class="ntwh-toolbar"><?php echo NTWH_Pro::lock( __( 'Resend all failed deliveries', 'nodethemes-webhooks' ), 'bulk-resend' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		<?php endif; ?>

		<div class="ntwh-card ntwh-log">
			<?php if ( ! $rows ) : ?>
				<p class="ntwh-empty"><?php esc_html_e( 'No deliveries match.', 'nodethemes-webhooks' ); ?></p>
			<?php else : ?>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$ep_name = isset( $endpoints[ $r->endpoint_id ] ) ? $endpoints[ $r->endpoint_id ]['name'] : ( 'inbound' === $r->object_type ? __( 'Inbound', 'nodethemes-webhooks' ) : __( '(deleted endpoint)', 'nodethemes-webhooks' ) );
					$obj     = '';
					if ( 'order' === $r->object_type && $r->object_id ) {
						$o   = wc_get_order( (int) $r->object_id );
						$obj = $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : '#' . (int) $r->object_id;
					} elseif ( $r->object_id ) {
						$obj = esc_html( $r->object_type . ' #' . (int) $r->object_id );
					}
					?>
					<details class="ntwh-row is-<?php echo esc_attr( $r->status ); ?>" <?php echo $open === (int) $r->id ? 'open' : ''; ?> data-id="<?php echo esc_attr( $r->id ); ?>">
						<summary>
							<span class="c-status"><?php echo self::badge( $r->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<span class="c-event"><code><?php echo esc_html( $r->event ); ?></code> <?php echo $obj; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<span class="c-ep"><?php echo esc_html( $ep_name ); ?></span>
							<span class="c-code"><?php echo $r->response_code ? esc_html( 'HTTP ' . $r->response_code ) : ( $r->attempts ? esc_html__( 'no answer', 'nodethemes-webhooks' ) : '' ); ?></span>
							<span class="c-time"><?php echo esc_html( self::when( $r->created ) ); ?></span>
						</summary>
						<?php self::delivery_details( $r ); ?>
					</details>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php if ( $pages > 1 ) : ?>
			<p class="ntwh-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $f['page'],
							'total'   => $pages,
						)
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		self::support_box();
		echo '</div>';
	}

	/**
	 * Expanded delivery details.
	 *
	 * @param object $r Row (without payload columns).
	 */
	private static function delivery_details( $r ) {
		$full = NTWH_DB::get( (int) $r->id );
		$body = $full && $full->request_body ? $full->request_body : ( $full ? $full->payload : '' );
		$pretty = $body ? wp_json_encode( json_decode( $body ), JSON_PRETTY_PRINT | NTWH_Payload::JSON_FLAGS ) : '';
		?>
		<div class="ntwh-details">
			<dl>
				<dt><?php esc_html_e( 'Event ID', 'nodethemes-webhooks' ); ?></dt><dd><code><?php echo esc_html( $r->event_uuid ); ?></code></dd>
				<dt><?php esc_html_e( 'Attempts', 'nodethemes-webhooks' ); ?></dt><dd><?php echo esc_html( (int) $r->attempts . ' / ' . (int) $r->max_attempts ); ?></dd>
				<?php if ( 'retrying' === $r->status && $r->next_attempt ) : ?>
					<dt><?php esc_html_e( 'Next try', 'nodethemes-webhooks' ); ?></dt><dd><?php echo esc_html( self::when( $r->next_attempt ) ); ?></dd>
				<?php endif; ?>
				<?php if ( $r->delivered ) : ?>
					<dt><?php esc_html_e( 'Delivered', 'nodethemes-webhooks' ); ?></dt><dd><?php echo esc_html( self::when( $r->delivered ) . ' (' . (int) $r->duration_ms . ' ms)' ); ?></dd>
				<?php endif; ?>
				<?php if ( '' !== (string) $r->error ) : ?>
					<dt><?php esc_html_e( 'Error', 'nodethemes-webhooks' ); ?></dt><dd class="ntwh-err"><?php echo esc_html( $r->error ); ?></dd>
				<?php endif; ?>
				<?php
				$ctx = json_decode( (string) $r->context, true );
				if ( is_array( $ctx ) && isset( $ctx['from'], $ctx['to'] ) ) :
					?>
					<dt><?php esc_html_e( 'Status change', 'nodethemes-webhooks' ); ?></dt><dd><?php echo esc_html( $ctx['from'] . ' → ' . $ctx['to'] ); ?></dd>
				<?php endif; ?>
			</dl>
			<?php if ( $full && '' !== (string) $full->response_body ) : ?>
				<h4><?php esc_html_e( 'Answer from the endpoint', 'nodethemes-webhooks' ); ?></h4>
				<pre class="ntwh-pre"><?php echo esc_html( $full->response_body ); ?></pre>
			<?php endif; ?>
			<?php if ( $pretty ) : ?>
				<h4><?php esc_html_e( 'Body sent', 'nodethemes-webhooks' ); ?></h4>
				<pre class="ntwh-pre"><?php echo esc_html( $pretty ); ?></pre>
			<?php endif; ?>
			<?php if ( in_array( $r->status, array( 'failed', 'success', 'skipped', 'resent' ), true ) && 'inbound' !== $r->object_type && 'test' !== $r->object_type ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ntwh_resend_' . $r->id ); ?>
					<input type="hidden" name="action" value="ntwh_resend">
					<input type="hidden" name="id" value="<?php echo esc_attr( $r->id ); ?>">
					<button class="ntwh-btn" type="submit"><?php esc_html_e( 'Resend now', 'nodethemes-webhooks' ); ?></button>
					<span class="ntwh-help"><?php esc_html_e( 'Sends the same event again (same Event ID, so your workflow can ignore duplicates).', 'nodethemes-webhooks' ); ?></span>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------------ */

	/**
	 * Settings & help.
	 */
	public static function page_settings() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$plan = NTWH_Dispatcher::retry_plan();
		$mins = array();
		foreach ( $plan as $p ) {
			$mins[] = $p < HOUR_IN_SECONDS ? sprintf( /* translators: %d: minutes */ _n( '%d minute', '%d minutes', (int) round( $p / 60 ), 'nodethemes-webhooks' ), (int) round( $p / 60 ) ) : sprintf( /* translators: %d: hours */ _n( '%d hour', '%d hours', (int) round( $p / 3600 ), 'nodethemes-webhooks' ), (int) round( $p / 3600 ) );
		}
		echo '<div class="wrap ntwh-wrap">';
		self::header( __( 'Settings & Help', 'nodethemes-webhooks' ) );
		?>
		<div class="ntwh-card">
			<h2><?php esc_html_e( 'Delivery & retries', 'nodethemes-webhooks' ); ?></h2>
			<ul class="ntwh-list">
				<li><?php esc_html_e( 'Webhooks are sent in the background (WooCommerce Action Scheduler), so checkout never waits for n8n / Make.', 'nodethemes-webhooks' ); ?></li>
				<li>
					<?php
					/* translators: 1: attempts, 2: delays */
					echo esc_html( sprintf( __( 'Each delivery is tried up to %1$d times. Waits between tries: %2$s.', 'nodethemes-webhooks' ), count( $plan ) + 1, implode( ', ', $mins ) ) );
					?>
				</li>
				<li><?php esc_html_e( 'Any 2xx answer counts as delivered. Every other answer is retried — also 404, which n8n returns while a workflow is switched off. Only 410 (Gone) stops the retries at once.', 'nodethemes-webhooks' ); ?></li>
				<li><?php esc_html_e( 'An endpoint is never switched off automatically. You see a warning here and in the dashboard when it keeps failing.', 'nodethemes-webhooks' ); ?></li>
				<li><?php esc_html_e( 'Tip for busy stores: run WP-Cron from a real server cron job, so background jobs also run at night when nobody visits the site.', 'nodethemes-webhooks' ); ?></li>
			</ul>
			<?php if ( ! NTWH_Pro::active() ) : ?>
				<p><?php echo NTWH_Pro::lock( __( 'Retry for up to 24 hours & email alerts', 'nodethemes-webhooks' ), 'retries' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php endif; ?>
			<?php
			/* translators: %d: days */
			echo '<p class="ntwh-help">' . esc_html( sprintf( __( 'Delivery history is kept for %d days.', 'nodethemes-webhooks' ), (int) apply_filters( 'ntwh_log_retention_days', NTWH_Pro::FREE_RETENTION ) ) ) . '</p>';
			?>
		</div>

		<?php do_action( 'ntwh_settings_pro' ); ?>

		<div class="ntwh-card">
			<h2><?php esc_html_e( 'What is sent', 'nodethemes-webhooks' ); ?></h2>
			<p><?php esc_html_e( 'A POST request with a JSON body:', 'nodethemes-webhooks' ); ?></p>
<pre class="ntwh-pre">{
  "id": "6f1c…",            // Event ID — the same on retries and resends
  "event": "order.paid",
  "created_at": "2026-10-21T12:00:00+00:00",
  "site": { "url": "…", "name": "…" },
  "context": { "from": "pending", "to": "processing" },
  "data": { "id": 123, "number": "123", "status": "processing", "total": "59.00",
            "currency": "EUR", "customer": { "email": "…", "phone": "…" },
            "billing": { … }, "shipping": { … }, "items": [ … ], "coupons": [ … ] }
}</pre>
			<p class="ntwh-help"><?php esc_html_e( 'Headers: X-NT-Event, X-NT-Event-Id, X-NT-Delivery-Id, X-NT-Attempt, X-NT-Timestamp, X-NT-Signature, X-NT-Site. Money values are strings, as in the WooCommerce REST API.', 'nodethemes-webhooks' ); ?></p>
		</div>

		<div class="ntwh-card">
			<h2><?php esc_html_e( 'Checking the signature', 'nodethemes-webhooks' ); ?></h2>
			<p><?php esc_html_e( 'X-NT-Signature = "sha256=" + HMAC-SHA256( X-NT-Timestamp + "." + raw body, your endpoint secret ). Reject requests whose timestamp is older than 5 minutes.', 'nodethemes-webhooks' ); ?></p>
			<h4>n8n</h4>
			<p class="ntwh-help"><?php esc_html_e( 'After the Webhook node add a "Crypto" node: Action = Hmac, Type = SHA256, Encoding = hex, Secret = your endpoint secret, Value =', 'nodethemes-webhooks' ); ?></p>
			<pre class="ntwh-pre">{{ $json.headers["x-nt-timestamp"] }}.{{ JSON.stringify($json.body) }}</pre>
			<p class="ntwh-help"><?php esc_html_e( 'Then an "If" node: "sha256=" + the Crypto result equals {{ $json.headers["x-nt-signature"] }}. The body is sent in a form that JSON.stringify reproduces exactly. Simpler alternative: use an authentication header (endpoint settings) with n8n "Header Auth".', 'nodethemes-webhooks' ); ?></p>
			<h4>PHP</h4>
<pre class="ntwh-pre">$body = file_get_contents( 'php://input' );
$ts   = $_SERVER['HTTP_X_NT_TIMESTAMP'] ?? '';
$sig  = 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $body, 'YOUR_SECRET' );
$ok   = hash_equals( $sig, $_SERVER['HTTP_X_NT_SIGNATURE'] ?? '' ) && abs( time() - (int) $ts ) &lt; 300;</pre>
			<h4>Node.js</h4>
<pre class="ntwh-pre">const crypto = require('crypto');
const sig = 'sha256=' + crypto.createHmac('sha256', SECRET).update(ts + '.' + rawBody).digest('hex');
const ok = crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(req.headers['x-nt-signature'] || ''));</pre>
		</div>

		<div class="ntwh-card">
			<h2><?php esc_html_e( 'Avoiding duplicates', 'nodethemes-webhooks' ); ?></h2>
			<p><?php esc_html_e( 'A retry or a manual resend carries the same Event ID ("id" in the body, X-NT-Event-Id header). If your workflow sends SMS or creates invoices, store the Event ID and skip IDs you have already handled.', 'nodethemes-webhooks' ); ?></p>
		</div>
		<?php
		self::support_box();
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Save endpoint.
	 */
	public static function handle_save_endpoint() {
		self::guard( 'ntwh_save_endpoint' );
		$raw = isset( $_POST['ep'] ) && is_array( $_POST['ep'] ) ? wp_unslash( $_POST['ep'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized field by field in NTWH_Endpoints::save().
		if ( ! empty( $raw['regenerate'] ) ) {
			$raw['secret'] = NTWH_Endpoints::new_secret();
		}
		$id = NTWH_Endpoints::save( $raw );
		if ( is_wp_error( $id ) ) {
			// Keep what was typed (sanitized) so the form is not emptied.
			set_transient(
				'ntwh_form_' . get_current_user_id(),
				array(
					'name'       => sanitize_text_field( isset( $raw['name'] ) ? $raw['name'] : '' ),
					'url'        => esc_url_raw( isset( $raw['url'] ) ? $raw['url'] : '' ),
					'events'     => array_map( 'sanitize_text_field', (array) ( isset( $raw['events'] ) ? $raw['events'] : array() ) ),
					'auth_name'  => NTWH_Endpoints::clean_header_name( isset( $raw['auth_name'] ) ? $raw['auth_name'] : '' ),
					'auth_value' => NTWH_Endpoints::clean_header_value( isset( $raw['auth_value'] ) ? $raw['auth_value'] : '' ),
				),
				300
			);
			self::flash( 'error', $id->get_error_message() );
			$back = ! empty( $raw['id'] ) ? array( 'edit' => sanitize_key( $raw['id'] ) ) : array( 'new' => 1 );
			self::go( 'ntwh', $back );
		}
		if ( ! get_transient( 'ntwh_flash_' . get_current_user_id() ) ) {
			self::flash( 'success', __( 'Endpoint saved. Press "Send test" to check the connection.', 'nodethemes-webhooks' ) );
		}
		self::go( 'ntwh' );
	}

	/**
	 * Delete endpoint.
	 */
	public static function handle_delete_endpoint() {
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		self::guard( 'ntwh_endpoint_' . $id );
		NTWH_Endpoints::delete( $id );
		self::flash( 'success', __( 'Endpoint deleted.', 'nodethemes-webhooks' ) );
		self::go( 'ntwh' );
	}

	/**
	 * Switch on/off.
	 */
	public static function handle_toggle_endpoint() {
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		self::guard( 'ntwh_endpoint_' . $id );
		$ep = NTWH_Endpoints::get( $id );
		if ( $ep ) {
			NTWH_Endpoints::set_active( $id, ! $ep['active'] );
			self::flash( 'success', $ep['active'] ? __( 'Endpoint switched off. New events are not sent to it.', 'nodethemes-webhooks' ) : __( 'Endpoint switched on.', 'nodethemes-webhooks' ) );
		}
		self::go( 'ntwh' );
	}

	/**
	 * Send test.
	 */
	public static function handle_test_endpoint() {
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		self::guard( 'ntwh_endpoint_' . $id );
		$row = NTWH_Dispatcher::test( $id );
		if ( is_wp_error( $row ) ) {
			self::flash( 'error', $row->get_error_message() );
			self::go( 'ntwh' );
		}
		if ( 'success' === $row->status ) {
			/* translators: 1: HTTP code, 2: milliseconds */
			self::flash( 'success', sprintf( __( 'Test delivered: HTTP %1$d in %2$d ms.', 'nodethemes-webhooks' ), (int) $row->response_code, (int) $row->duration_ms ) );
		} else {
			/* translators: %s: error */
			self::flash( 'error', sprintf( __( 'Test failed: %s', 'nodethemes-webhooks' ), $row->error ) );
		}
		self::go( 'ntwh-deliveries', array( 'open' => (int) $row->id, 'endpoint_id' => $id ) );
	}

	/**
	 * Resend one delivery.
	 */
	public static function handle_resend() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::guard( 'ntwh_resend_' . $id );
		$new = NTWH_Dispatcher::resend( $id, true );
		if ( is_wp_error( $new ) ) {
			self::flash( 'error', $new->get_error_message() );
			self::go( 'ntwh-deliveries' );
		}
		$row = NTWH_DB::get( $new );
		if ( $row && 'success' === $row->status ) {
			self::flash( 'success', __( 'Resent and delivered.', 'nodethemes-webhooks' ) );
		} elseif ( $row && 'skipped' === $row->status ) {
			self::flash( 'warning', $row->error );
		} else {
			self::flash( 'error', __( 'Resent, but the endpoint did not accept it. It will be retried automatically.', 'nodethemes-webhooks' ) . ( $row && $row->error ? ' ' . $row->error : '' ) );
		}
		self::go( 'ntwh-deliveries', array( 'open' => (int) $new ) );
	}

	/**
	 * Save settings (Pro adds fields through the ntwh_save_settings filter).
	 */
	public static function handle_save_settings() {
		self::guard( 'ntwh_settings' );
		$raw = isset( $_POST['ntwh'] ) && is_array( $_POST['ntwh'] ) ? wp_unslash( $_POST['ntwh'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by the filter callbacks.
		$s   = (array) apply_filters( 'ntwh_save_settings', self::settings(), $raw );
		update_option( 'ntwh_settings', $s, false );
		self::flash( 'success', __( 'Settings saved.', 'nodethemes-webhooks' ) );
		self::go( 'ntwh-settings' );
	}
}
