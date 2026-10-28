<?php
/**
 * Free / Pro split. The Pro code lives in the separate "NodeThemes Webhooks Pro" add-on.
 * In the free plugin every Pro feature only shows a locked button that explains what it does.
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro gate.
 */
final class NTWH_Pro {

	const UPGRADE_URL     = 'https://nodethemes.com/product/nodethemes-webhooks-pro/';
	const FREE_ENDPOINTS  = 3;
	const FREE_RETENTION  = 7;

	/**
	 * Is the Pro add-on installed and active? (It announces itself through this filter.)
	 *
	 * @return bool
	 */
	public static function has_code() {
		return (bool) apply_filters( 'ntwh_pro_installed', false );
	}

	/**
	 * Installed AND licensed: the add-on answers this filter only while its license allows Pro features.
	 *
	 * @return bool
	 */
	public static function active() {
		return (bool) apply_filters( 'ntwh_is_pro', false );
	}

	/**
	 * License page of the add-on.
	 *
	 * @return string
	 */
	public static function license_url() {
		return admin_url( 'admin.php?page=ntwh-license' );
	}

	/**
	 * Where a locked button leads.
	 *
	 * @param string $feature Feature id (for the campaign tag).
	 * @return string
	 */
	public static function url( $feature = '' ) {
		if ( self::has_code() && ! self::active() ) {
			return self::license_url();
		}
		return add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => 'lock',
				'utm_campaign' => sanitize_key( $feature ),
			),
			self::UPGRADE_URL
		);
	}

	/**
	 * A locked button.
	 *
	 * @param string $label   Label.
	 * @param string $feature Feature id.
	 * @param string $class   CSS class.
	 * @return string
	 */
	public static function lock( $label, $feature, $class = 'ntwh-btn' ) {
		return '<a class="' . esc_attr( $class ) . ' ntwh-locked" href="' . esc_url( self::url( $feature ) ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Pro feature - click to see what it includes', 'nodethemes-webhooks' ) . '"><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html( $label ) . ' <span class="ntwh-protag">PRO</span></a>';
	}

	/**
	 * PRO tag.
	 *
	 * @return string
	 */
	public static function badge() {
		return '<span class="ntwh-protag">PRO</span>';
	}

	/**
	 * Pro feature list (shown in upsell boxes).
	 *
	 * @return string[]
	 */
	public static function features() {
		return array(
			__( 'Unlimited endpoints (free: 3)', 'nodethemes-webhooks' ),
			__( 'Conditions: send only matching orders (total, status, payment method, country, product, coupon)', 'nodethemes-webhooks' ),
			__( 'Custom payload builder with {{placeholders}} and extra request headers', 'nodethemes-webhooks' ),
			__( 'Smart retries for up to 24 hours (free: 3 tries in about 15 minutes)', 'nodethemes-webhooks' ),
			__( 'Email alert when an endpoint fails, and again when it recovers', 'nodethemes-webhooks' ),
			__( 'Bulk resend of failed deliveries and "Send again" on any order', 'nodethemes-webhooks' ),
			__( 'More events: refunds, new customers, low stock and out of stock', 'nodethemes-webhooks' ),
			__( 'Inbound webhook: let n8n / Make update order status, add notes and save tracking numbers', 'nodethemes-webhooks' ),
			__( 'Ready-made n8n workflows and up to 90 days of delivery history', 'nodethemes-webhooks' ),
		);
	}

	/**
	 * Upsell box.
	 *
	 * @param string $title Title.
	 * @param string $text  Intro text.
	 */
	public static function upsell_box( $title = '', $text = '' ) {
		echo '<div class="ntwh-card ntwh-pro-box"><h2><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html( $title ? $title : __( 'Pro feature', 'nodethemes-webhooks' ) ) . '</h2>';
		echo '<p class="ntwh-help">' . ( $text ? esc_html( $text ) . ' ' : '' ) . esc_html__( 'Included in NodeThemes Webhooks Pro:', 'nodethemes-webhooks' ) . '</p><ul class="ntwh-list">';
		foreach ( self::features() as $f ) {
			echo '<li>' . esc_html( $f ) . '</li>';
		}
		echo '</ul><p><a class="ntwh-btn primary" target="_blank" rel="noopener" href="' . esc_url( self::url( 'box' ) ) . '">' . esc_html( self::has_code() ? __( 'Activate your license', 'nodethemes-webhooks' ) : __( 'See Pro', 'nodethemes-webhooks' ) ) . '</a></p></div>';
	}
}
