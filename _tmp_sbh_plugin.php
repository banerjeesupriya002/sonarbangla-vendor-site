<?php
/**
 * Plugin Name: SBH Vendor Onboarding
 * Description: Back-end for vendors.sonarbanglahaat.com — receives vendor applications, emails support@ for review, lets you approve/reject with a reason, and emails the vendor. Includes optional GST portal lookup (captcha) and a secure post-approval payout-details link.
 * Version: 1.3.0
 * Author: Sonar Bangla Haat
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SBH_Vendor_Onboarding {

	const CPT   = 'sbh_vendor';
	const NS    = 'sbh/v1';
	const VER   = '1.0.0';

	private static $inst;
	public static function instance() { return self::$inst ?: ( self::$inst = new self() ); }

	/* ------------------------------------------------------------------ config */
	private function admin_email() { return defined( 'SBH_ADMIN_EMAIL' ) ? SBH_ADMIN_EMAIL : 'support@sonarbanglahaat.com'; }
	private function front()       { return rtrim( defined( 'SBH_FRONT_URL' ) ? SBH_FRONT_URL : 'https://vendors.sonarbanglahaat.com', '/' ); }
	private function allowed_origins() { return apply_filters( 'sbh_allowed_origins', array( $this->front() ) ); }

	private function categories() {
		return array(
			'Traditional Rice & Grains' => 1, 'Cold-Pressed Mustard Oil' => 1, 'Pure Cow Ghee' => 1, 'Bengali Spices & Masala' => 1,
			'Bengali Chanachur & Mixtures' => 1, 'Tea' => 1, 'Snacks & Nimki' => 1, 'Bori & Sun-Dried Foods' => 1,
			'Traditional Achar & Kasundi' => 1, 'Traditional Jaggery & Gur' => 1, 'Traditional Bengali Sweets' => 1, 'Bengali Pithe & Nadu' => 1,
			'Bengal Handicrafts & Home Decor' => 0, 'Bengal Artisan Crafts & Regional Specialities' => 0, 'Bengali Gift Hampers & Combos' => 1,
			'Incense, Dhoop & Puja Items' => 0, 'Bengali Tea & Beverages' => 1, 'Others' => 0,
		);
	}
	private function states() {
		return array( 'Andaman and Nicobar Islands','Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chandigarh','Chhattisgarh','Dadra and Nagar Haveli and Daman and Diu','Delhi','Goa','Gujarat','Haryana','Himachal Pradesh','Jammu and Kashmir','Jharkhand','Karnataka','Kerala','Ladakh','Lakshadweep','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Puducherry','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal' );
	}
	private function gst_states() {
		return array( '01'=>'Jammu and Kashmir','02'=>'Himachal Pradesh','03'=>'Punjab','04'=>'Chandigarh','05'=>'Uttarakhand','06'=>'Haryana','07'=>'Delhi','08'=>'Rajasthan','09'=>'Uttar Pradesh','10'=>'Bihar','11'=>'Sikkim','12'=>'Arunachal Pradesh','13'=>'Nagaland','14'=>'Manipur','15'=>'Mizoram','16'=>'Tripura','17'=>'Meghalaya','18'=>'Assam','19'=>'West Bengal','20'=>'Jharkhand','21'=>'Odisha','22'=>'Chhattisgarh','23'=>'Madhya Pradesh','24'=>'Gujarat','26'=>'Dadra and Nagar Haveli and Daman and Diu','27'=>'Maharashtra','29'=>'Karnataka','30'=>'Goa','31'=>'Lakshadweep','32'=>'Kerala','33'=>'Tamil Nadu','34'=>'Puducherry','35'=>'Andaman and Nicobar Islands','36'=>'Telangana','37'=>'Andhra Pradesh','38'=>'Ladakh' );
	}
	private function doc_labels() {
		return array( 'pan_document' => 'PAN card', 'cheque_document' => 'Cancelled cheque / passbook', 'gst_document' => 'GST certificate', 'declaration_document' => 'GST declaration (signed)', 'fssai_document' => 'FSSAI licence' );
	}

	/* -------------------------------------------------------------------- boot */
	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'rest_api_init', array( $this, 'cors' ), 15 );

		add_action( 'add_meta_boxes_' . self::CPT, array( $this, 'metaboxes' ) );
		add_action( 'admin_post_sbh_decide', array( $this, 'handle_decision' ) );
		add_action( 'admin_post_sbh_doc', array( $this, 'handle_doc' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'cols' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'col_val' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_filter( 'add_menu_classes', array( $this, 'menu_badge' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . self::CPT, '__return_empty_array' );
	}

	public static function activate() {
		$i = self::instance();
		$i->docs_dir();
		$i->register_cpt();
		flush_rewrite_rules();
	}

	public function register_cpt() {
		register_post_type( self::CPT, array(
			'labels' => array( 'name' => 'Vendor Applications', 'singular_name' => 'Vendor Application', 'menu_name' => 'Vendor Applications', 'edit_item' => 'Review Application', 'search_items' => 'Search Applications', 'not_found' => 'No applications yet.' ),
			'public' => false, 'show_ui' => true, 'show_in_menu' => true, 'show_in_rest' => false, 'menu_position' => 26,
			'menu_icon' => 'dashicons-store', 'supports' => array( 'title' ), 'capability_type' => 'post',
			'capabilities' => array( 'create_posts' => 'do_not_allow' ), 'map_meta_cap' => true, 'has_archive' => false, 'rewrite' => false,
		) );
	}

	/* -------------------------------------------------------------------- CORS */
	public function cors() {
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		add_filter( 'rest_pre_serve_request', function ( $served ) {
			$origin = get_http_origin();
			if ( $origin && in_array( $origin, $this->allowed_origins(), true ) ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
				header( 'Access-Control-Allow-Headers: Content-Type' );
				header( 'Access-Control-Max-Age: 600' );
				header( 'Vary: Origin', false );
			}
			header( 'Cache-Control: no-store' );
			return $served;
		} );
	}

	/* ------------------------------------------------------------------ routes */
	public function routes() {
		$any = array( 'permission_callback' => '__return_true' );
		register_rest_route( self::NS, '/draft', array_merge( $any, array( 'methods' => 'POST', 'callback' => array( $this, 'r_draft' ) ) ) );
		register_rest_route( self::NS, '/draft/save', array_merge( $any, array( 'methods' => 'POST', 'callback' => array( $this, 'r_save' ) ) ) );
		register_rest_route( self::NS, '/draft/resume', array_merge( $any, array( 'methods' => 'POST', 'callback' => array( $this, 'r_resume' ) ) ) );
		register_rest_route( self::NS, '/draft/(?P<token>[a-f0-9]{32})', array_merge( $any, array( 'methods' => 'GET', 'callback' => array( $this, 'r_get_draft' ) ) ) );
		register_rest_route( self::NS, '/gst/captcha', array_merge( $any, array( 'methods' => 'GET', 'callback' => array( $this, 'r_gst_captcha' ) ) ) );
		register_rest_route( self::NS, '/gst/lookup', array_merge( $any, array( 'methods' => 'POST', 'callback' => array( $this, 'r_gst_lookup' ) ) ) );
		register_rest_route( self::NS, '/submit', array_merge( $any, array( 'methods' => 'POST', 'callback' => array( $this, 'r_submit' ) ) ) );
		register_rest_route( self::NS, '/payout/(?P<token>[a-f0-9]{32})', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'r_payout_get' ), 'permission_callback' => '__return_true' ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'r_payout_post' ), 'permission_callback' => '__return_true' ),
		) );
	}

	/* --------------------------------------------------------------- utilities */
	private function fail( $msg, $status = 400, $code = 'invalid' ) { return new WP_Error( $code, $msg, array( 'status' => $status ) ); }

	private function ip() {
		$ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0' );
		return preg_replace( '/[^0-9a-fA-F:\.]/', '', $ip );
	}
	/** Simple fixed-window rate limit. Returns true if allowed. */
	private function rl( $bucket, $max, $window = HOUR_IN_SECONDS, $who = null ) {
		$k = 'sbh_rl_' . md5( $bucket . '|' . ( $who ?: $this->ip() ) );
		$n = (int) get_transient( $k );
		if ( $n >= $max ) { return false; }
		set_transient( $k, $n + 1, $window );
		return true;
	}
	private function throttled() { return $this->fail( 'Too many attempts. Please try again in a little while.', 429, 'rate_limited' ); }
	private function token() { return bin2hex( random_bytes( 16 ) ); }
	private function norm_phone( $v ) {
		$d = preg_replace( '/\D/', '', (string) $v );
		if ( strlen( $d ) === 12 && strpos( $d, '91' ) === 0 ) { $d = substr( $d, 2 ); }
		if ( strlen( $d ) === 11 && $d[0] === '0' ) { $d = substr( $d, 1 ); }
		return $d;
	}
	private function ok_phone( $v ) { return (bool) preg_match( '/^[6-9]\d{9}$/', $v ); }
	private function ok_gstin( $g ) {
		if ( ! preg_match( '/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $g ) ) { return false; }
		$cs = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $sum = 0;
		for ( $i = 0; $i < 14; $i++ ) { $v = strpos( $cs, $g[ $i ] ) * ( $i % 2 === 0 ? 1 : 2 ); $sum += intdiv( $v, 36 ) + ( $v % 36 ); }
		return $cs[ ( 36 - ( $sum % 36 ) ) % 36 ] === $g[14];
	}
	private function find_by( $key, $value, $extra = array() ) {
		$q = get_posts( array_merge( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => $key, 'meta_value' => $value, 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true ), $extra ) );
		return $q ? $q[0] : null;
	}
	private function status( $id ) { return get_post_meta( $id, '_sbh_status', true ) ?: 'draft'; }
	private function data( $id ) { $d = get_post_meta( $id, '_sbh_data', true ); return is_array( $d ) ? $d : array(); }
	private function log( $id, $text ) {
		$l = get_post_meta( $id, '_sbh_log', true ); $l = is_array( $l ) ? $l : array();
		$u = wp_get_current_user();
		$l[] = array( 't' => time(), 'u' => $u && $u->exists() ? $u->display_name : 'Applicant', 'x' => $text );
		update_post_meta( $id, '_sbh_log', $l );
	}
	private function enc( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return 'p:' . $plain; }
		$key = hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'sbh' ) . 'sbh-bank', true );
		$iv  = random_bytes( 16 );
		return 'e:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv ) );
	}
	private function dec( $blob ) {
		if ( strpos( $blob, 'p:' ) === 0 ) { return substr( $blob, 2 ); }
		if ( strpos( $blob, 'e:' ) !== 0 || ! function_exists( 'openssl_decrypt' ) ) { return ''; }
		$raw = base64_decode( substr( $blob, 2 ) );
		$key = hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'sbh' ) . 'sbh-bank', true );
		return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
	}

	/** Whitelist + sanitise the applicant-provided fields. Returns array of cleaned values for keys that are present. */
	private function clean( $in ) {
		$out = array();
		$txt = array( 'owner_name' => 100, 'brand_name' => 120, 'legal_name' => 160, 'address_line_1' => 160, 'address_line_2' => 160, 'district' => 80 );
		foreach ( $txt as $k => $max ) { if ( isset( $in[ $k ] ) ) { $out[ $k ] = mb_substr( sanitize_text_field( $in[ $k ] ), 0, $max ); } }
		if ( isset( $in['email'] ) )          { $out['email'] = sanitize_email( $in['email'] ); }
		if ( isset( $in['mobile'] ) )         { $out['mobile'] = $this->norm_phone( $in['mobile'] ); }
		if ( isset( $in['whatsapp'] ) )       { $out['whatsapp'] = $this->norm_phone( $in['whatsapp'] ); }
		if ( isset( $in['pan_number'] ) )     { $out['pan_number'] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $in['pan_number'] ) ); }
		if ( isset( $in['gstin'] ) )          { $out['gstin'] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $in['gstin'] ) ); }
		if ( isset( $in['pin_code'] ) )       { $out['pin_code'] = preg_replace( '/\D/', '', $in['pin_code'] ); }
		if ( isset( $in['fssai_number'] ) )   { $out['fssai_number'] = preg_replace( '/\D/', '', $in['fssai_number'] ); }
		if ( isset( $in['brand_story'] ) )    { $out['brand_story'] = mb_substr( sanitize_textarea_field( $in['brand_story'] ), 0, 2000 ); }
		if ( isset( $in['state'] ) )          { $out['state'] = in_array( $in['state'], $this->states(), true ) ? $in['state'] : ''; }
		if ( isset( $in['gst_registered'] ) ) { $out['gst_registered'] = $in['gst_registered'] === 'yes' ? 'yes' : ( $in['gst_registered'] === 'no' ? 'no' : '' ); }
		if ( isset( $in['fssai_has'] ) )      { $out['fssai_has'] = $in['fssai_has'] === 'yes' ? 'yes' : ( $in['fssai_has'] === 'no' ? 'no' : '' ); }
		if ( isset( $in['product_categories'] ) ) {
			$c = $in['product_categories'];
			if ( is_string( $c ) ) { $c = json_decode( wp_unslash( $c ), true ); }
			$out['product_categories'] = array_values( array_intersect( is_array( $c ) ? array_map( 'strval', $c ) : array(), array_keys( $this->categories() ) ) );
		}
		return $out;
	}
	private function has_food( $cats ) { $all = $this->categories(); foreach ( (array) $cats as $c ) { if ( ! empty( $all[ $c ] ) ) { return true; } } return false; }
	private function body( WP_REST_Request $r ) {
		$j = $r->get_json_params();
		return is_array( $j ) ? $j : $r->get_params();
	}

	/* ------------------------------------------------------------ draft routes */
	public function r_draft( WP_REST_Request $r ) {
		$b = $this->body( $r );
		if ( ! empty( $b['website'] ) ) { return array( 'token' => $this->token(), 'emailed' => false ); } // honeypot
		if ( ! $this->rl( 'draft', 12 ) ) { return $this->throttled(); }
		$d = $this->clean( $b );
		if ( empty( $d['email'] ) || ! is_email( $d['email'] ) )        { return $this->fail( 'Please enter a valid email address.' ); }
		if ( empty( $d['owner_name'] ) || mb_strlen( $d['owner_name'] ) < 2 ) { return $this->fail( 'Please enter the owner\'s full name.' ); }
		if ( empty( $d['mobile'] ) || ! $this->ok_phone( $d['mobile'] ) ) { return $this->fail( 'Please enter a valid 10-digit mobile number.' ); }
		if ( ! empty( $d['whatsapp'] ) && ! $this->ok_phone( $d['whatsapp'] ) ) { return $this->fail( 'Please enter a valid WhatsApp number.' ); }

		$post = null;
		if ( ! empty( $b['token'] ) && preg_match( '/^[a-f0-9]{32}$/', $b['token'] ) ) {
			$post = $this->find_by( '_sbh_token', $b['token'] );
			if ( $post && $this->status( $post->ID ) !== 'draft' ) { $post = null; }
		}
		$emailed = false;
		if ( $post ) {
			$old = $this->data( $post->ID );
			update_post_meta( $post->ID, '_sbh_data', array_merge( $old, $d ) );
			$emailed = ( isset( $old['email'] ) && $old['email'] !== $d['email'] );
			$token = get_post_meta( $post->ID, '_sbh_token', true );
		} else {
			$token = $this->token();
			$id = wp_insert_post( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $d['owner_name'] . ' — ' . $d['email'] ), true );
			if ( is_wp_error( $id ) ) { return $this->fail( 'Could not save. Please try again.', 500 ); }
			update_post_meta( $id, '_sbh_token', $token );
			update_post_meta( $id, '_sbh_status', 'draft' );
			update_post_meta( $id, '_sbh_data', $d );
			$post = get_post( $id ); $emailed = true;
		}
		update_post_meta( $post->ID, '_sbh_email', $d['email'] );
		update_post_meta( $post->ID, '_sbh_step', 4 );
		if ( $emailed ) { $this->mail_resume( $d['email'], $d['owner_name'], $token ); }
		return array( 'token' => $token, 'emailed' => $emailed );
	}

	public function r_save( WP_REST_Request $r ) {
		if ( ! $this->rl( 'save', 200 ) ) { return $this->throttled(); }
		$b = $this->body( $r );
		$post = ( ! empty( $b['token'] ) && preg_match( '/^[a-f0-9]{32}$/', $b['token'] ) ) ? $this->find_by( '_sbh_token', $b['token'] ) : null;
		if ( ! $post || $this->status( $post->ID ) !== 'draft' ) { return $this->fail( 'Session not found.', 404, 'not_found' ); }
		$d = $this->clean( isset( $b['data'] ) && is_array( $b['data'] ) ? $b['data'] : array() );
		unset( $d['email'] ); // email is changed only through /draft
		update_post_meta( $post->ID, '_sbh_data', array_merge( $this->data( $post->ID ), $d ) );
		if ( isset( $b['step'] ) ) { update_post_meta( $post->ID, '_sbh_step', max( 3, min( 6, (int) $b['step'] ) ) ); }
		return array( 'ok' => true );
	}

	public function r_resume( WP_REST_Request $r ) {
		$b = $this->body( $r ); $email = isset( $b['email'] ) ? sanitize_email( $b['email'] ) : '';
		if ( ! is_email( $email ) ) { return $this->fail( 'Please enter a valid email address.' ); }
		if ( ! $this->rl( 'resume', 6 ) || ! $this->rl( 'resume_e', 3, HOUR_IN_SECONDS, strtolower( $email ) ) ) { return $this->throttled(); }
		$post = $this->find_draft_by_email( $email );
		if ( $post ) { $d = $this->data( $post->ID ); $this->mail_resume( $email, isset( $d['owner_name'] ) ? $d['owner_name'] : '', get_post_meta( $post->ID, '_sbh_token', true ) ); }
		return array( 'ok' => true ); // identical response whether or not a draft exists
	}
	private function find_draft_by_email( $email ) {
		$q = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true,
			'meta_query' => array( 'relation' => 'AND', array( 'key' => '_sbh_email', 'value' => $email ), array( 'key' => '_sbh_status', 'value' => 'draft' ) ) ) );
		return $q ? $q[0] : null;
	}

	public function r_get_draft( WP_REST_Request $r ) {
		if ( ! $this->rl( 'getdraft', 60 ) ) { return $this->throttled(); }
		$post = $this->find_by( '_sbh_token', $r['token'] );
		if ( ! $post || $this->status( $post->ID ) !== 'draft' ) { return $this->fail( 'This link has expired.', 404, 'not_found' ); }
		$dd = $this->data( $post->ID ); $gv = get_post_meta( $post->ID, '_sbh_gstv', true );
		if ( is_array( $gv ) && ! empty( $dd['gstin'] ) && isset( $gv['gstin'] ) && $gv['gstin'] === $dd['gstin'] ) { $dd['gst_status'] = $gv['status']; }
		return array( 'data' => $dd, 'step' => (int) get_post_meta( $post->ID, '_sbh_step', true ) );
	}

	/* --------------------------------------------------------------- GST portal */
	private function gst_headers( $extra = array() ) {
		return array_merge( array( 'User-Agent' => 'Mozilla/5.0 (compatible; SBH-VendorOnboarding/1.0; +https://sonarbanglahaat.com)', 'Referer' => 'https://services.gst.gov.in/services/searchtp', 'Accept-Language' => 'en-IN,en;q=0.9' ), $extra );
	}
	private function cookie_map( $resp ) {
		$m = array();
		foreach ( (array) wp_remote_retrieve_cookies( $resp ) as $c ) { if ( $c instanceof WP_Http_Cookie ) { $m[ $c->name ] = $c->value; } }
		return $m;
	}
	public function r_gst_captcha() {
		if ( ! $this->rl( 'gstcap', 40 ) ) { return $this->throttled(); }
		$p = wp_remote_get( 'https://services.gst.gov.in/services/searchtp', array( 'timeout' => 15, 'headers' => $this->gst_headers() ) );
		if ( is_wp_error( $p ) ) { return $this->fail( 'GST portal unavailable.', 503, 'unavailable' ); }
		$cookies = $this->cookie_map( $p );
		$c = wp_remote_get( 'https://services.gst.gov.in/services/captcha?rnd=' . wp_rand(), array( 'timeout' => 15, 'cookies' => $cookies, 'headers' => $this->gst_headers( array( 'Accept' => 'image/*' ) ) ) );
		if ( is_wp_error( $c ) || (int) wp_remote_retrieve_response_code( $c ) !== 200 ) { return $this->fail( 'GST portal unavailable.', 503, 'unavailable' ); }
		$body = wp_remote_retrieve_body( $c ); $ct = wp_remote_retrieve_header( $c, 'content-type' );
		if ( ! $body || stripos( (string) $ct, 'image/' ) !== 0 ) { return $this->fail( 'GST portal unavailable.', 503, 'unavailable' ); }
		$cookies = array_merge( $cookies, $this->cookie_map( $c ) );
		$sid = bin2hex( random_bytes( 12 ) );
		set_transient( 'sbh_gst_' . $sid, $cookies, 10 * MINUTE_IN_SECONDS );
		return array( 'sid' => $sid, 'image' => 'data:' . strtok( $ct, ';' ) . ';base64,' . base64_encode( $body ) );
	}
	public function r_gst_lookup( WP_REST_Request $r ) {
		if ( ! $this->rl( 'gstlook', 30 ) ) { return $this->throttled(); }
		$b = $this->body( $r );
		$g = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', isset( $b['gstin'] ) ? $b['gstin'] : '' ) );
		$cap = isset( $b['captcha'] ) ? trim( (string) $b['captcha'] ) : '';
		$sid = isset( $b['sid'] ) ? preg_replace( '/[^a-f0-9]/', '', $b['sid'] ) : '';
		if ( ! $this->ok_gstin( $g ) ) { return $this->fail( 'Enter a valid GSTIN.' ); }
		if ( $cap === '' || strlen( $cap ) > 12 ) { return $this->fail( 'Enter the captcha.' ); }
		$cookies = $sid ? get_transient( 'sbh_gst_' . $sid ) : false;
		if ( ! is_array( $cookies ) ) { return $this->fail( 'Captcha expired. Please load a new one.', 400, 'bad_captcha' ); }
		delete_transient( 'sbh_gst_' . $sid );

		$res = wp_remote_post( 'https://services.gst.gov.in/services/api/search/tp', array(
			'timeout' => 20, 'cookies' => $cookies,
			'headers' => $this->gst_headers( array( 'Content-Type' => 'application/json;charset=UTF-8', 'Accept' => 'application/json, text/plain, */*', 'Origin' => 'https://services.gst.gov.in' ) ),
			'body'    => wp_json_encode( array( 'gstin' => $g, 'captcha' => $cap ) ),
		) );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 500 ) { return $this->fail( 'GST portal unavailable.', 503, 'unavailable' ); }
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $j ) ) { return $this->fail( 'GST portal unavailable.', 503, 'unavailable' ); }
		if ( ! empty( $j['errorCode'] ) ) {
			if ( $j['errorCode'] === 'SWEB_9000' ) { return $this->fail( 'Captcha did not match.', 400, 'bad_captcha' ); }
			$msg = ! empty( $j['message'] ) ? sanitize_text_field( $j['message'] ) : 'GSTIN not found on the GST portal.';
			return $this->fail( $msg, 404, 'not_found' );
		}
		$m = $this->map_gst( $j, $g );
		$tok = isset( $b['token'] ) ? (string) $b['token'] : '';
		if ( preg_match( '/^[a-f0-9]{32}$/', $tok ) ) {
			$p = $this->find_by( '_sbh_token', $tok );
			if ( $p && $this->status( $p->ID ) === 'draft' ) { update_post_meta( $p->ID, '_sbh_gstv', array( 'gstin' => $g, 'status' => $m['status'], 'ts' => time() ) ); }
		}
		return array( 'data' => $m );
	}
	private function map_gst( $j, $g ) {
		$pr  = isset( $j['pradr'] ) && is_array( $j['pradr'] ) ? $j['pradr'] : array();
		$a   = isset( $pr['addr'] ) && is_array( $pr['addr'] ) ? $pr['addr'] : array();
		$s   = function ( $k ) use ( $a ) { return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? trim( sanitize_text_field( (string) $a[ $k ] ) ) : ''; };
		$l1  = implode( ', ', array_filter( array( $s( 'flno' ), $s( 'bno' ), $s( 'bnm' ) ) ) );
		$l2s = array_filter( array( $l1 ? $s( 'st' ) : '', $s( 'loc' ), $s( 'city' ) ) );
		if ( ! $l1 ) { $l1 = $s( 'st' ) ?: $s( 'loc' ); $l2s = $s( 'st' ) ? array_filter( array( $s( 'loc' ) ) ) : array(); }
		$l2  = implode( ', ', $l2s );
		$dst = $s( 'dst' ); $sn = $s( 'stcd' ); $pin = preg_replace( '/\D/', '', $s( 'pncd' ) );

		// The portal sometimes returns the principal address only as one text line ("adr"): split it.
		$adr = '';
		foreach ( array( isset( $pr['adr'] ) ? $pr['adr'] : '', isset( $j['adr'] ) ? $j['adr'] : '' ) as $cand ) { if ( is_string( $cand ) && trim( $cand ) !== '' ) { $adr = $cand; break; } }
		if ( $adr !== '' && ( ! $l1 || ! $pin || ! $dst ) ) {
			$adr = trim( sanitize_text_field( $adr ) );
			if ( ! $pin && preg_match_all( '/\b(\d{6})\b/', $adr, $m ) ) { $pin = end( $m[1] ); }
			$parts = array_values( array_filter( array_map( 'trim', explode( ',', $adr ) ), 'strlen' ) );
			while ( $parts && ( preg_match( '/^\d{6}$/', end( $parts ) ) || strtolower( end( $parts ) ) === 'india' ) ) { array_pop( $parts ); }
			if ( $parts ) {
				$last = end( $parts );
				foreach ( $this->states() as $st ) { if ( strcasecmp( $st, $last ) === 0 ) { if ( ! in_array( $sn, $this->states(), true ) ) { $sn = $st; } array_pop( $parts ); break; } }
			}
			if ( $parts && ! $dst && count( $parts ) >= 2 ) { $dst = array_pop( $parts ); }
			if ( $parts && ! $l1 ) {
				if ( count( $parts ) >= 3 ) { $l2 = array_pop( $parts ); $l1 = implode( ', ', $parts ); }
				elseif ( count( $parts ) == 2 ) { $l1 = $parts[0]; $l2 = $parts[1]; }
				else { $l1 = $parts[0]; }
			}
		}
		$gs = $this->gst_states();
		if ( ! in_array( $sn, $this->states(), true ) ) { $sn = isset( $gs[ substr( $g, 0, 2 ) ] ) ? $gs[ substr( $g, 0, 2 ) ] : ''; }
		return array(
			'legal_name' => isset( $j['lgnm'] ) ? sanitize_text_field( $j['lgnm'] ) : '',
			'trade_name' => isset( $j['tradeNam'] ) ? sanitize_text_field( $j['tradeNam'] ) : '',
			'status'     => isset( $j['sts'] ) ? sanitize_text_field( $j['sts'] ) : '',
			'pan'        => substr( $g, 2, 10 ),
			'address_line_1' => $l1, 'address_line_2' => $l2,
			'district'   => $dst, 'state' => $sn, 'pin_code' => substr( $pin, 0, 6 ),
		);
	}

	/* ----------------------------------------------------------------- uploads */
	private function docs_dir() {
		$u = wp_upload_dir(); $dir = trailingslashit( $u['basedir'] ) . 'sbh-vendor-docs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}
	private function store_file( $key, $post_id ) {
		if ( empty( $_FILES[ $key ] ) || empty( $_FILES[ $key ]['name'] ) ) { return null; }
		$f = $_FILES[ $key ];
		if ( (int) $f['error'] !== UPLOAD_ERR_OK ) { return new WP_Error( 'upload', 'Upload failed for ' . $this->doc_labels()[ $key ] . '. Please try again.' ); }
		if ( $f['size'] > 5 * 1024 * 1024 ) { return new WP_Error( 'upload', $this->doc_labels()[ $key ] . ' is larger than 5MB.' ); }
		$ext = strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) );
		$ok  = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' );
		if ( ! isset( $ok[ $ext ] ) ) { return new WP_Error( 'upload', $this->doc_labels()[ $key ] . ' must be a JPG, PNG or PDF.' ); }
		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $f['tmp_name'] ) : $ok[ $ext ];
		if ( $mime !== $ok[ $ext ] ) { return new WP_Error( 'upload', $this->doc_labels()[ $key ] . ' is not a valid ' . strtoupper( $ext ) . ' file.' ); }
		$name = $post_id . '-' . $key . '-' . bin2hex( random_bytes( 10 ) ) . '.' . $ext;
		if ( ! move_uploaded_file( $f['tmp_name'], $this->docs_dir() . '/' . $name ) ) { return new WP_Error( 'upload', 'Could not store ' . $this->doc_labels()[ $key ] . '.' ); }
		return array( 'file' => $name, 'orig' => sanitize_file_name( $f['name'] ), 'mime' => $mime );
	}

	/* ------------------------------------------------------------------ submit */
	public function r_submit( WP_REST_Request $r ) {
		$b = $r->get_params();
		if ( ! empty( $b['website'] ) ) { return array( 'ok' => true ); } // honeypot
		if ( ! $this->rl( 'submit', 20 ) ) { return $this->throttled(); }
		$post = ( ! empty( $b['token'] ) && preg_match( '/^[a-f0-9]{32}$/', $b['token'] ) ) ? $this->find_by( '_sbh_token', $b['token'] ) : null;
		if ( ! $post || $this->status( $post->ID ) !== 'draft' ) { return $this->fail( 'Your session has expired. Please start again from the sign-up step.', 400, 'session' ); }

		$d = array_merge( $this->data( $post->ID ), $this->clean( $b ) );
		$gst = isset( $d['gst_registered'] ) ? $d['gst_registered'] : '';
		if ( empty( $d['email'] ) || ! is_email( $d['email'] ) ) { return $this->fail( 'A valid email is required.' ); }
		if ( empty( $d['owner_name'] ) )                          { return $this->fail( 'Owner name is required.' ); }
		if ( empty( $d['mobile'] ) || ! $this->ok_phone( $d['mobile'] ) ) { return $this->fail( 'A valid mobile number is required.' ); }
		if ( empty( $d['brand_name'] ) )                          { return $this->fail( 'Brand / business name is required.' ); }
		if ( empty( $d['pan_number'] ) || ! preg_match( '/^[A-Z]{5}[0-9]{4}[A-Z]$/', $d['pan_number'] ) ) { return $this->fail( 'A valid PAN is required.' ); }
		if ( $gst !== 'yes' && $gst !== 'no' )                    { return $this->fail( 'Please tell us whether you are GST registered.' ); }
		if ( $gst === 'yes' ) {
			if ( empty( $d['gstin'] ) || ! $this->ok_gstin( $d['gstin'] ) ) { return $this->fail( 'A valid GSTIN is required.' ); }
			if ( substr( $d['gstin'], 2, 10 ) !== $d['pan_number'] )         { return $this->fail( 'PAN does not match the GSTIN.' ); }
		} else { $d['gstin'] = ''; }
		foreach ( array( 'address_line_1' => 'Address line 1', 'district' => 'District', 'state' => 'State' ) as $k => $lab ) { if ( empty( $d[ $k ] ) ) { return $this->fail( $lab . ' is required.' ); } }
		if ( empty( $d['pin_code'] ) || ! preg_match( '/^\d{6}$/', $d['pin_code'] ) ) { return $this->fail( 'A valid 6-digit pin code is required.' ); }
		if ( empty( $d['product_categories'] ) ) { return $this->fail( 'Select at least one product category.' ); }
		if ( $this->has_food( $d['product_categories'] ) ) {
			$fh = isset( $d['fssai_has'] ) ? $d['fssai_has'] : '';
			if ( $fh !== 'yes' && $fh !== 'no' ) { return $this->fail( 'Please tell us whether you have an FSSAI licence.' ); }
			if ( $fh === 'yes' && ! preg_match( '/^\d{14}$/', isset( $d['fssai_number'] ) ? $d['fssai_number'] : '' ) ) { return $this->fail( 'FSSAI number must be 14 digits.' ); }
			if ( $fh === 'no' ) { $d['fssai_number'] = ''; }
		} else { $d['fssai_has'] = ''; $d['fssai_number'] = ''; }

		// duplicates among live applications
		foreach ( array( '_sbh_email' => $d['email'], '_sbh_gstin' => $d['gstin'] ) as $mk => $mv ) {
			if ( $mv === '' ) { continue; }
			$dup = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => 1, 'exclude' => array( $post->ID ), 'fields' => 'ids', 'no_found_rows' => true,
				'meta_query' => array( 'relation' => 'AND', array( 'key' => $mk, 'value' => $mv ), array( 'key' => '_sbh_status', 'value' => array( 'pending', 'approved' ), 'compare' => 'IN' ) ) ) );
			if ( $dup ) { return $this->fail( 'An application with this ' . ( $mk === '_sbh_email' ? 'email address' : 'GSTIN' ) . ' already exists. Please write to ' . $this->admin_email() . ' if you need help.', 409, 'duplicate' ); }
		}

		// files
		$gv = get_post_meta( $post->ID, '_sbh_gstv', true );
		$gstat = ( $gst === 'yes' && is_array( $gv ) && isset( $gv['gstin'], $gv['status'] ) && $gv['gstin'] === $d['gstin'] ) ? trim( (string) $gv['status'] ) : '';
		$d['gst_status'] = $gstat;
		$need = array( 'cheque_document' ); // always required for payouts
		if ( strtolower( $gstat ) !== 'active' ) { // PAN / GST proof only when the GST portal has not confirmed an Active GSTIN
			$need[] = 'pan_document';
			if ( $gst === 'yes' ) { $need[] = ( $gstat === '' ) ? 'gst_document' : 'declaration_document'; }
		}
		foreach ( $need as $k ) { if ( empty( $_FILES[ $k ]['name'] ) ) { return $this->fail( 'Please upload: ' . $this->doc_labels()[ $k ] . '.' ); } }
		$docs = array();
		foreach ( array_keys( $this->doc_labels() ) as $k ) {
			$res = $this->store_file( $k, $post->ID );
			if ( is_wp_error( $res ) ) { foreach ( $docs as $x ) { @unlink( $this->docs_dir() . '/' . $x['file'] ); } return $this->fail( $res->get_error_message() ); }
			if ( $res ) { $docs[ $k ] = $res; }
		}

		update_post_meta( $post->ID, '_sbh_data', $d );
		update_post_meta( $post->ID, '_sbh_docs', $docs );
		update_post_meta( $post->ID, '_sbh_email', $d['email'] );
		update_post_meta( $post->ID, '_sbh_gstin', $d['gstin'] );
		update_post_meta( $post->ID, '_sbh_status', 'pending' );
		update_post_meta( $post->ID, '_sbh_submitted', time() );
		delete_post_meta( $post->ID, '_sbh_token' );
		wp_update_post( array( 'ID' => $post->ID, 'post_title' => $d['brand_name'] . ' — ' . $d['owner_name'] ) );
		$this->log( $post->ID, 'Application submitted.' );

		$this->mail_admin_new( $post->ID, $d );
		$this->mail_vendor_received( $d );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ payout */
	private function payout_post( $token ) {
		$p = $this->find_by( '_sbh_payout_token', hash( 'sha256', $token ) );
		return ( $p && $this->status( $p->ID ) === 'approved' && ! get_post_meta( $p->ID, '_sbh_bank', true ) ) ? $p : null;
	}
	public function r_payout_get( WP_REST_Request $r ) {
		if ( ! $this->rl( 'payget', 30 ) ) { return $this->throttled(); }
		$p = $this->payout_post( $r['token'] );
		if ( ! $p ) { return $this->fail( 'Link not valid.', 404, 'not_found' ); }
		$d = $this->data( $p->ID );
		return array( 'brand' => isset( $d['brand_name'] ) ? $d['brand_name'] : '', 'owner' => isset( $d['owner_name'] ) ? $d['owner_name'] : '' );
	}
	public function r_payout_post( WP_REST_Request $r ) {
		$b = $this->body( $r );
		if ( ! empty( $b['website'] ) ) { return array( 'ok' => true ); }
		if ( ! $this->rl( 'paypost', 8 ) ) { return $this->throttled(); }
		$p = $this->payout_post( $r['token'] );
		if ( ! $p ) { return $this->fail( 'This link is no longer valid.', 404, 'not_found' ); }
		$holder = mb_substr( sanitize_text_field( isset( $b['holder'] ) ? $b['holder'] : '' ), 0, 120 );
		$bank   = mb_substr( sanitize_text_field( isset( $b['bank'] ) ? $b['bank'] : '' ), 0, 100 );
		$ifsc   = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', isset( $b['ifsc'] ) ? $b['ifsc'] : '' ) );
		$acct   = preg_replace( '/\D/', '', isset( $b['account'] ) ? $b['account'] : '' );
		if ( strlen( $holder ) < 3 || strlen( $bank ) < 2 )        { return $this->fail( 'Please enter the account holder and bank name.' ); }
		if ( ! preg_match( '/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc ) )   { return $this->fail( 'Please enter a valid IFSC code.' ); }
		if ( ! preg_match( '/^\d{9,18}$/', $acct ) )                { return $this->fail( 'Please enter a valid account number.' ); }
		update_post_meta( $p->ID, '_sbh_bank', array( 'holder' => $holder, 'bank' => $bank, 'ifsc' => $ifsc, 'account' => $this->enc( $acct ), 'last4' => substr( $acct, -4 ), 'at' => time() ) );
		delete_post_meta( $p->ID, '_sbh_payout_token' );
		$this->log( $p->ID, 'Vendor submitted payout details.' );
		$d = $this->data( $p->ID );
		$this->send( $this->admin_email(), 'Payout details received: ' . $d['brand_name'], $this->tpl( 'Payout details received', '<p><strong>' . esc_html( $d['brand_name'] ) . '</strong> (' . esc_html( $d['owner_name'] ) . ') has submitted bank details through the secure link.</p><p>Account ending <strong>' . esc_html( substr( $acct, -4 ) ) . '</strong>. Open the application in WordPress to view the full details.</p>', 'Open application', get_edit_post_link( $p->ID, 'raw' ) ) );
		$this->send( $d['email'], 'We\'ve received your payout details', $this->tpl( 'Payout details received', '<p>Hello ' . esc_html( $d['owner_name'] ) . ',</p><p>Thank you — your payout details for <strong>' . esc_html( $d['brand_name'] ) . '</strong> have been saved securely. Our team will contact you shortly to help you create your first product listing.</p>' ) );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ emails */
	private function tpl( $title, $html, $btn_label = '', $btn_url = '' ) {
		$logo = $this->front() . '/assets/logo-256.png';
		$btn  = $btn_label ? '<p style="margin:28px 0 8px"><a href="' . esc_url( $btn_url ) . '" style="background:#a73a22;color:#fdfbf7;text-decoration:none;padding:14px 24px;font-size:13px;letter-spacing:.14em;text-transform:uppercase;display:inline-block">' . esc_html( $btn_label ) . '</a></p>' : '';
		return '<!doctype html><html><body style="margin:0;background:#f5efe6;font-family:Arial,Helvetica,sans-serif;color:#3b1713">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5efe6;padding:24px 12px"><tr><td align="center">'
			. '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fdfbf7;border:1px solid #e2d5c4">'
			. '<tr><td style="padding:24px 28px;border-bottom:1px solid #e2d5c4"><img src="' . esc_url( $logo ) . '" alt="Sonar Bangla Haat" width="84" height="84" style="display:block;border:0"></td></tr>'
			. '<tr><td style="padding:28px;font-size:15px;line-height:1.6"><h1 style="font-family:Georgia,serif;font-weight:normal;font-size:26px;margin:0 0 16px;color:#3b1713">' . esc_html( $title ) . '</h1>' . $html . $btn . '</td></tr>'
			. '<tr><td style="padding:18px 28px;border-top:1px solid #e2d5c4;font-size:12px;color:#96817e">Team Sonar Bangla Haat · <a href="mailto:' . esc_attr( $this->admin_email() ) . '" style="color:#a73a22">' . esc_html( $this->admin_email() ) . '</a></td></tr>'
			. '</table></td></tr></table></body></html>';
	}
	private function send( $to, $subject, $html, $reply_to = '' ) {
		$h = array( 'Content-Type: text/html; charset=UTF-8', 'From: Sonar Bangla Haat <' . $this->admin_email() . '>' );
		if ( $reply_to ) { $h[] = 'Reply-To: ' . $reply_to; }
		return wp_mail( $to, $subject, $html, $h );
	}
	private function mail_resume( $email, $name, $token ) {
		$url = $this->front() . '/?resume=' . $token;
		$this->send( $email, 'Continue your Sonar Bangla Haat vendor application', $this->tpl( 'Pick up where you left off', '<p>Hello ' . esc_html( $name ?: 'there' ) . ',</p><p>Your vendor application is saved. Use the private link below to continue — it only works for you, so please don\'t share it.</p>', 'Continue application', $url ) );
	}
	private function mail_vendor_received( $d ) {
		$this->send( $d['email'], 'We\'ve received your application — Sonar Bangla Haat', $this->tpl( 'Your application is in', '<p>Hello ' . esc_html( $d['owner_name'] ) . ',</p><p>Thank you for applying to sell <strong>' . esc_html( $d['brand_name'] ) . '</strong> on Sonar Bangla Haat. Our team reviews every application within <strong>24 hours</strong> and we\'ll email you the outcome.</p><p>If you have any questions in the meantime, simply reply to this email.</p>' ), $this->admin_email() );
	}
	private function row( $k, $v ) { return '<tr><td style="padding:6px 12px 6px 0;color:#96817e;vertical-align:top;white-space:nowrap">' . esc_html( $k ) . '</td><td style="padding:6px 0">' . esc_html( $v !== '' ? $v : '—' ) . '</td></tr>'; }
	private function mail_admin_new( $id, $d ) {
		$cats = implode( ', ', (array) $d['product_categories'] );
		$rows = $this->row( 'Brand', $d['brand_name'] ) . $this->row( 'Owner', $d['owner_name'] ) . $this->row( 'Email', $d['email'] ) . $this->row( 'Mobile', $d['mobile'] )
			. $this->row( 'GST', $d['gstin'] ? $d['gstin'] . ( ! empty( $d['gst_status'] ) ? ' (portal status: ' . $d['gst_status'] . ')' : '' ) : 'Not registered / exempt' ) . $this->row( 'PAN', $d['pan_number'] )
			. $this->row( 'Address', implode( ', ', array_filter( array( $d['address_line_1'], $d['address_line_2'], $d['district'], $d['state'], $d['pin_code'] ) ) ) )
			. $this->row( 'Categories', $cats );
		if ( $this->has_food( $d['product_categories'] ) ) { $rows .= $this->row( 'FSSAI', $d['fssai_has'] === 'yes' ? $d['fssai_number'] : 'Not yet — advised to apply' ); }
		$html = '<p>A new vendor application is waiting for your review. Open it in WordPress to view the uploaded documents, then <strong>approve</strong> or <strong>reject with a reason</strong>. The vendor is emailed automatically.</p><table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;margin-top:8px">' . $rows . '</table>';
		$this->send( $this->admin_email(), 'New vendor application: ' . $d['brand_name'] . ' (' . $d['owner_name'] . ')', $this->tpl( 'New vendor application', $html, 'Review application', get_edit_post_link( $id, 'raw' ) ), $d['email'] );
	}

	/* ------------------------------------------------------- decision handling */
	public function handle_decision() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed', 403 ); }
		$id = isset( $_POST['sbh_id'] ) ? (int) $_POST['sbh_id'] : 0;
		check_admin_referer( 'sbh_decide_' . $id, 'sbh_nonce' );
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== self::CPT ) { wp_die( 'Not found', 404 ); }
		$act = isset( $_POST['sbh_action'] ) ? sanitize_key( $_POST['sbh_action'] ) : '';
		$back = function ( $m ) use ( $id ) { wp_safe_redirect( add_query_arg( 'sbh_msg', $m, get_edit_post_link( $id, 'raw' ) ) ); exit; };
		$d = $this->data( $id ); $st = $this->status( $id );

		if ( $act === 'approve' && $st !== 'draft' ) {
			$tok = $this->token();
			update_post_meta( $id, '_sbh_status', 'approved' );
			update_post_meta( $id, '_sbh_payout_token', hash( 'sha256', $tok ) );
			delete_post_meta( $id, '_sbh_reject_reason' );
			$this->log( $id, 'Approved.' );
			$url = $this->front() . '/payout.html?t=' . $tok;
			$ok = $this->send( $d['email'], 'Welcome to Sonar Bangla Haat — your application is approved', $this->tpl( 'You\'re in — welcome, Founding Vendor!',
				'<p>Hello ' . esc_html( $d['owner_name'] ) . ',</p><p>Great news — <strong>' . esc_html( $d['brand_name'] ) . '</strong> has been approved as a Sonar Bangla Haat vendor.</p><p><strong>Next step:</strong> add your bank details through this private, secure link so we can set up your weekly payouts. The link works once.</p><p>Our team will then help you list and photograph your products ahead of our launch in February–March 2027.</p>', 'Add payout details', $url ), $this->admin_email() );
			$back( $ok ? 'approved' : 'approved_nomail' );
		}
		if ( $act === 'reject' && $st !== 'draft' ) {
			$reason = isset( $_POST['sbh_reason'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['sbh_reason'] ) ) ) : '';
			if ( mb_strlen( $reason ) < 5 ) { $back( 'need_reason' ); }
			update_post_meta( $id, '_sbh_status', 'rejected' );
			update_post_meta( $id, '_sbh_reject_reason', $reason );
			delete_post_meta( $id, '_sbh_payout_token' );
			$this->log( $id, 'Rejected. Reason: ' . $reason );
			$ok = $this->send( $d['email'], 'Update on your Sonar Bangla Haat vendor application', $this->tpl( 'About your application',
				'<p>Hello ' . esc_html( $d['owner_name'] ) . ',</p><p>Thank you for your interest in selling <strong>' . esc_html( $d['brand_name'] ) . '</strong> on Sonar Bangla Haat. After review, we aren\'t able to approve your application at this time.</p><p><strong>Reason:</strong><br>' . nl2br( esc_html( $reason ) ) . '</p><p>If you can address this, you\'re welcome to apply again, or reply to this email and we\'ll be happy to help.</p>', 'Apply again', $this->front() . '/' ), $this->admin_email() );
			$back( $ok ? 'rejected' : 'rejected_nomail' );
		}
		if ( $act === 'reopen' && in_array( $st, array( 'approved', 'rejected' ), true ) ) {
			update_post_meta( $id, '_sbh_status', 'pending' ); delete_post_meta( $id, '_sbh_payout_token' );
			$this->log( $id, 'Re-opened for review.' );
			$back( 'reopened' );
		}
		$back( 'noop' );
	}
	public function admin_notice() {
		if ( empty( $_GET['sbh_msg'] ) ) { return; }
		$m = array(
			'approved' => array( 'updated', 'Approved. The vendor has been emailed with their secure payout-details link.' ),
			'approved_nomail' => array( 'warning', 'Approved, but the email could not be sent. Check your WordPress mail (SMTP) settings and contact the vendor directly.' ),
			'rejected' => array( 'updated', 'Rejected. The vendor has been emailed with your reason.' ),
			'rejected_nomail' => array( 'warning', 'Rejected, but the email could not be sent. Check your WordPress mail (SMTP) settings and contact the vendor directly.' ),
			'need_reason' => array( 'error', 'Please write a reason (at least 5 characters) before rejecting — it is sent to the vendor.' ),
			'reopened' => array( 'updated', 'Application re-opened for review.' ),
		);
		$k = sanitize_key( $_GET['sbh_msg'] ); if ( ! isset( $m[ $k ] ) ) { return; }
		echo '<div class="notice notice-' . esc_attr( $m[ $k ][0] === 'updated' ? 'success' : $m[ $k ][0] ) . ' is-dismissible"><p>' . esc_html( $m[ $k ][1] ) . '</p></div>';
	}

	/* ------------------------------------------------------------ document view */
	public function handle_doc() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed', 403 ); }
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; $k = isset( $_GET['k'] ) ? sanitize_key( $_GET['k'] ) : '';
		check_admin_referer( 'sbh_doc_' . $id . $k );
		$docs = get_post_meta( $id, '_sbh_docs', true );
		if ( ! is_array( $docs ) || empty( $docs[ $k ] ) ) { wp_die( 'Not found', 404 ); }
		$path = $this->docs_dir() . '/' . basename( $docs[ $k ]['file'] );
		if ( ! is_file( $path ) ) { wp_die( 'File missing', 404 ); }
		nocache_headers();
		header( 'Content-Type: ' . $docs[ $k ]['mime'] );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline; filename="' . $docs[ $k ]['orig'] . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); exit;
	}

	/* ------------------------------------------------------------------ admin UI */
	public function metaboxes() {
		add_meta_box( 'sbh_decision', 'Decision', array( $this, 'mb_decision' ), self::CPT, 'side', 'high' );
		add_meta_box( 'sbh_app', 'Application details', array( $this, 'mb_app' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'sbh_bank', 'Payout details', array( $this, 'mb_bank' ), self::CPT, 'normal', 'default' );
		add_meta_box( 'sbh_log', 'Activity', array( $this, 'mb_log' ), self::CPT, 'side', 'default' );
		remove_meta_box( 'submitdiv', self::CPT, 'side' );
	}
	private function badge( $st ) {
		$c = array( 'draft' => '#96817e', 'pending' => '#d89e25', 'approved' => '#3d6b3a', 'rejected' => '#a73a22' );
		return '<span style="display:inline-block;padding:2px 10px;border-radius:10px;color:#fff;font-size:12px;background:' . esc_attr( isset( $c[ $st ] ) ? $c[ $st ] : '#999' ) . '">' . esc_html( ucfirst( $st ) ) . '</span>';
	}
	public function mb_decision( $post ) {
		$st = $this->status( $post->ID );
		echo '<p>Status: ' . $this->badge( $st ) . '</p>';
		wp_nonce_field( 'sbh_decide_' . $post->ID, 'sbh_nonce' );
		echo '<input type="hidden" name="sbh_id" value="' . (int) $post->ID . '">';
		$fa = esc_url( admin_url( 'admin-post.php?action=sbh_decide' ) );
		echo '<script>function sbhGo(a,m){if(!confirm(m))return;var f=document.createElement("form");f.method="post";f.action="' . $fa . '";var v={sbh_id:document.querySelector("[name=sbh_id]").value,sbh_nonce:document.querySelector("[name=sbh_nonce]").value,sbh_action:a,sbh_reason:(document.getElementById("sbh_reason")||{}).value||""};for(var k in v){var i=document.createElement("input");i.type="hidden";i.name=k;i.value=v[k];f.appendChild(i);}document.body.appendChild(f);f.submit();}</script>';
		if ( $st === 'draft' ) { echo '<p>This applicant has not submitted yet.</p>'; return; }
		if ( $st === 'pending' ) {
			echo '<p><button type="button" class="button button-primary button-large" style="width:100%" onclick="sbhGo(\'approve\',\'Approve this vendor and email them?\')">Approve &amp; email vendor</button></p><hr>';
			echo '<p><label for="sbh_reason"><strong>Reason for rejection</strong> (sent to the vendor)</label><textarea id="sbh_reason" name="sbh_reason" rows="4" style="width:100%" placeholder="e.g. GST certificate is unreadable — please re-apply with a clear copy."></textarea></p>';
			echo '<p><button type="button" class="button" style="width:100%;color:#a73a22;border-color:#a73a22" onclick="sbhGo(\'reject\',\'Reject this vendor and email the reason?\')">Reject with reason</button></p>';
		} else {
			if ( $st === 'rejected' ) { echo '<p><strong>Reason sent:</strong><br>' . nl2br( esc_html( get_post_meta( $post->ID, '_sbh_reject_reason', true ) ) ) . '</p>'; }
			echo '<p><button type="button" class="button" onclick="sbhGo(\'reopen\',\'Re-open this application for a new decision?\')">Re-open for review</button></p>';
		}
	}
	public function mb_app( $post ) {
		$d = $this->data( $post->ID ); $docs = get_post_meta( $post->ID, '_sbh_docs', true ); $docs = is_array( $docs ) ? $docs : array();
		$st = $this->status( $post->ID );
		if ( ! $d ) { echo '<p>No data.</p>'; return; }
		$g = function ( $k ) use ( $d ) { return isset( $d[ $k ] ) ? $d[ $k ] : ''; };
		$rows = array(
			'Brand' => $g( 'brand_name' ), 'Legal name' => $g( 'legal_name' ), 'Owner' => $g( 'owner_name' ), 'Email' => $g( 'email' ), 'Mobile' => $g( 'mobile' ), 'WhatsApp' => $g( 'whatsapp' ),
			'GST registered' => $g( 'gst_registered' ) === 'yes' ? 'Yes — ' . $g( 'gstin' ) . ( $g( 'gst_status' ) ? ' · Portal status: ' . $g( 'gst_status' ) : '' ) : ( $g( 'gst_registered' ) === 'no' ? 'No / exempt' : '' ), 'PAN' => $g( 'pan_number' ),
			'Address' => implode( ', ', array_filter( array( $g( 'address_line_1' ), $g( 'address_line_2' ), $g( 'district' ), $g( 'state' ), $g( 'pin_code' ) ) ) ),
			'Categories' => implode( ', ', (array) $g( 'product_categories' ) ),
		);
		if ( $this->has_food( $g( 'product_categories' ) ) ) { $rows['FSSAI'] = $g( 'fssai_has' ) === 'yes' ? 'Yes — ' . $g( 'fssai_number' ) : ( $g( 'fssai_has' ) === 'no' ? 'Not yet (advised to apply)' : '' ); }
		$rows['Brand story'] = $g( 'brand_story' );
		$sub = get_post_meta( $post->ID, '_sbh_submitted', true ); if ( $sub ) { $rows['Submitted'] = wp_date( 'j M Y, g:i a', $sub ); }
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $k => $v ) { echo '<tr><th style="width:150px">' . esc_html( $k ) . '</th><td>' . ( $v !== '' ? nl2br( esc_html( $v ) ) : '—' ) . '</td></tr>'; }
		echo '</tbody></table><h4>Documents</h4>';
		if ( ! $docs ) { echo '<p>No documents uploaded yet.</p>'; return; }
		echo '<ul>';
		foreach ( $docs as $k => $x ) {
			$u = wp_nonce_url( admin_url( 'admin-post.php?action=sbh_doc&id=' . $post->ID . '&k=' . $k ), 'sbh_doc_' . $post->ID . $k );
			echo '<li><a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html( $this->doc_labels()[ $k ] ) . '</a> <span style="color:#777">(' . esc_html( $x['orig'] ) . ')</span></li>';
		}
		echo '</ul>';
	}
	public function mb_bank( $post ) {
		$b = get_post_meta( $post->ID, '_sbh_bank', true );
		if ( ! is_array( $b ) ) { echo '<p>' . ( $this->status( $post->ID ) === 'approved' ? 'Waiting for the vendor to submit bank details through their secure link.' : 'Collected only after approval.' ) . '</p>'; return; }
		echo '<table class="widefat striped"><tbody>';
		foreach ( array( 'Account holder' => $b['holder'], 'Bank' => $b['bank'], 'IFSC' => $b['ifsc'], 'Account number' => $this->dec( $b['account'] ), 'Received' => wp_date( 'j M Y, g:i a', $b['at'] ) ) as $k => $v ) {
			echo '<tr><th style="width:150px">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">Account number is stored encrypted and shown only to administrators.</p>';
	}
	public function mb_log( $post ) {
		$l = get_post_meta( $post->ID, '_sbh_log', true );
		if ( ! is_array( $l ) || ! $l ) { echo '<p>No activity yet.</p>'; return; }
		echo '<ul style="margin:0">';
		foreach ( array_reverse( $l ) as $e ) { echo '<li style="margin-bottom:8px"><span style="color:#777">' . esc_html( wp_date( 'j M, g:i a', $e['t'] ) ) . ' · ' . esc_html( $e['u'] ) . '</span><br>' . esc_html( $e['x'] ) . '</li>'; }
		echo '</ul>';
	}

	/* list table */
	public function cols( $c ) {
		return array( 'cb' => '', 'title' => 'Application', 'sbh_contact' => 'Contact', 'sbh_cats' => 'Categories', 'sbh_gst' => 'GST / FSSAI', 'sbh_status' => 'Status', 'sbh_date' => 'Submitted' );
	}
	public function col_val( $col, $id ) {
		$d = $this->data( $id );
		switch ( $col ) {
			case 'sbh_contact': echo esc_html( isset( $d['email'] ) ? $d['email'] : '' ) . '<br>' . esc_html( isset( $d['mobile'] ) ? $d['mobile'] : '' ); break;
			case 'sbh_cats': echo esc_html( implode( ', ', array_slice( isset( $d['product_categories'] ) ? (array) $d['product_categories'] : array(), 0, 3 ) ) ); break;
			case 'sbh_gst': echo esc_html( ! empty( $d['gstin'] ) ? $d['gstin'] : ( isset( $d['gst_registered'] ) && $d['gst_registered'] === 'no' ? 'No GST' : '—' ) ); if ( ! empty( $d['fssai_number'] ) ) { echo '<br>FSSAI ' . esc_html( $d['fssai_number'] ); } break;
			case 'sbh_status': echo $this->badge( $this->status( $id ) ); break; // phpcs:ignore WordPress.Security.EscapeOutput
			case 'sbh_date': $s = get_post_meta( $id, '_sbh_submitted', true ); echo $s ? esc_html( wp_date( 'j M Y', $s ) ) : '—'; break;
		}
	}
	public function row_actions( $a, $post ) { if ( $post->post_type === self::CPT ) { return array( 'edit' => '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">Review</a>' ); } return $a; }
	public function filter_dropdown() {
		global $typenow; if ( $typenow !== self::CPT ) { return; }
		$cur = isset( $_GET['sbh_status'] ) ? sanitize_key( $_GET['sbh_status'] ) : 'pending';
		echo '<select name="sbh_status">';
		foreach ( array( '' => 'All statuses', 'pending' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'draft' => 'Incomplete (drafts)' ) as $k => $l ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $l ) . '</option>'; }
		echo '</select>';
	}
	public function filter_query( $q ) {
		global $pagenow;
		if ( ! is_admin() || ! $q->is_main_query() || $pagenow !== 'edit.php' || $q->get( 'post_type' ) !== self::CPT ) { return; }
		$s = isset( $_GET['sbh_status'] ) ? sanitize_key( $_GET['sbh_status'] ) : 'pending';
		if ( $s !== '' ) { $q->set( 'meta_query', array( array( 'key' => '_sbh_status', 'value' => $s ) ) ); }
		if ( ! $q->get( 'orderby' ) ) { $q->set( 'orderby', 'ID' ); $q->set( 'order', 'DESC' ); }
	}
	public function menu_badge( $menu ) {
		$n = (int) ( new WP_Query( array( 'post_type' => self::CPT, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => array( array( 'key' => '_sbh_status', 'value' => 'pending' ) ) ) ) )->found_posts;
		if ( $n ) { foreach ( $menu as $k => $m ) { if ( isset( $m[2] ) && $m[2] === 'edit.php?post_type=' . self::CPT ) { $menu[ $k ][0] .= ' <span class="update-plugins count-' . $n . '"><span class="plugin-count">' . $n . '</span></span>'; } } }
		return $menu;
	}
}

register_activation_hook( __FILE__, array( 'SBH_Vendor_Onboarding', 'activate' ) );
SBH_Vendor_Onboarding::instance();

/* =====================================================================
 * Orders, returns, replacements & customer experience (WooCommerce + Dokan) — v3
 *  Order life-cycle:  Processing -> Shipped -> Delivered -> (7 days, no return) Completed.
 *  Vendors may only set Processing / Shipped / Delivered; Completed is automatic, and
 *  Dokan pays vendors only on Completed orders.
 *  Return / replacement: customer asks within 7 days of delivery; the seller has 3 days
 *  to accept or reject (otherwise auto-accepted). Accepted return -> order becomes
 *  "Returned"; accepted replacement -> "Replacement". If a replacement is not available
 *  the seller can offer a refund instead (-> Returned). Everyone gets an email each step.
 * ===================================================================== */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) { \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true ); }
} );

final class SBH_Returns {
	const DAYS         = 7;   // return window after delivery
	const VENDOR_DAYS  = 3;   // vendor response time
	const CANCEL_HOURS = 3;   // customer can cancel only this long after placing the order
	const META         = '_sbh_return';
	private static $internal = false;

	public static function reasons() {
		return array(
			'damaged'   => 'Damaged or defective product',
			'incorrect' => 'Incorrect item received',
			'different' => 'Product significantly different from its description',
			'other'     => 'Other (please explain below)',
		);
	}
	private static function support() { return defined( 'SBH_ADMIN_EMAIL' ) ? SBH_ADMIN_EMAIL : 'support@sonarbanglahaat.com'; }
	private static function type( $d ) { return ( isset( $d['type'] ) && $d['type'] === 'replacement' ) ? 'replacement' : 'return'; }
	public static function lbl( $d ) {
		$rep = self::type( $d ) === 'replacement'; $s = $d['status'];
		if ( $s === 'requested' ) { return $rep ? 'Replacement requested' : 'Return requested'; }
		if ( $s === 'rejected' )  { return $rep ? 'Replacement rejected' : 'Return rejected'; }
		$m = array( 'returned' => 'Returned', 'replacement' => 'Replacement approved', 'replaced' => 'Replaced' );
		return $m[ $s ] ?? $s;
	}
	private static function vendor_statuses() { return array( 'processing', 'sbh-shipped', 'sbh-delivered' ); }

	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) { return; }
		self::register_statuses();
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', function ( $s ) { return array_merge( $s, array( 'sbh-shipped', 'sbh-delivered', 'sbh-returned', 'sbh-replacement' ) ); } );
		add_filter( 'woocommerce_reports_order_statuses', function ( $s ) { return is_array( $s ) ? array_merge( $s, array( 'sbh-shipped', 'sbh-delivered' ) ) : $s; } );
		add_action( 'woocommerce_order_status_sbh-shipped', array( __CLASS__, 'on_shipped' ), 10, 2 );
		add_action( 'woocommerce_order_status_sbh-delivered', array( __CLASS__, 'on_delivered' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_changed' ), 10, 4 );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( __CLASS__, 'cancel_statuses' ), 10, 2 );
		add_action( 'woocommerce_cancelled_order', array( __CLASS__, 'after_cancel' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_my_account_my_orders_column_order-status', array( __CLASS__, 'list_status_extra' ), 20 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_customer' ) );
		add_action( 'admin_post_sbh_return_request', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_post_sbh_return_decide', array( __CLASS__, 'handle_decide' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'dokan_order_detail_after_order_items', array( __CLASS__, 'render_vendor' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'col' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'col' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'col_val' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'col_val' ), 10, 2 );
		add_action( 'sbh_returns_cron', array( __CLASS__, 'cron' ) );
		if ( ! wp_next_scheduled( 'sbh_returns_cron' ) ) { wp_schedule_event( time() + 300, 'hourly', 'sbh_returns_cron' ); }
		add_filter( 'woocommerce_email_subject_customer_processing_order', array( __CLASS__, 'cod_subject' ), 10, 2 );
		add_filter( 'woocommerce_email_heading_customer_processing_order', array( __CLASS__, 'cod_heading' ), 10, 2 );
		add_filter( 'woocommerce_min_password_strength', function () { return 2; } );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'dashboard' ) );
		add_action( 'wp_head', array( __CLASS__, 'css' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'js' ), 99 );
	}

	/* ------------------------------------------------------------ statuses */
	private static function register_statuses() {
		foreach ( array( 'wc-sbh-shipped' => 'Shipped', 'wc-sbh-delivered' => 'Delivered', 'wc-sbh-returned' => 'Returned', 'wc-sbh-replacement' => 'Replacement' ) as $k => $l ) {
			register_post_status( $k, array( 'label' => $l, 'public' => true, 'exclude_from_search' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop( $l . ' <span class="count">(%s)</span>', $l . ' <span class="count">(%s)</span>' ) ) );
		}
	}
	public static function add_statuses( $s ) {
		$extra = array( 'wc-sbh-shipped' => 'Shipped', 'wc-sbh-delivered' => 'Delivered', 'wc-sbh-returned' => 'Returned', 'wc-sbh-replacement' => 'Replacement' );
		$o = array();
		foreach ( $s as $k => $v ) { $o[ $k ] = $v; if ( $k === 'wc-processing' ) { $o += $extra; } }
		foreach ( $extra as $k => $v ) { if ( ! isset( $o[ $k ] ) ) { $o[ $k ] = $v; } }
		// Sellers in the Dokan dashboard only get Processing / Shipped / Delivered (plus the order's current status).
		if ( ! is_admin() && function_exists( 'dokan_is_seller_dashboard' ) && dokan_is_seller_dashboard() && ! current_user_can( 'manage_woocommerce' ) ) {
			$keep = array_map( function ( $x ) { return 'wc-' . $x; }, self::vendor_statuses() );
			$oid = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
			if ( $oid ) { $ord = wc_get_order( $oid ); if ( $ord ) { $keep[] = 'wc-' . $ord->get_status(); } }
			$o = array_intersect_key( $o, array_flip( $keep ) );
		}
		return $o;
	}
	public static function on_changed( $id, $from, $to, $order ) {
		// Sellers cannot move an order to any status other than the three allowed ones.
		if ( ! self::$internal && is_user_logged_in() && ! current_user_can( 'manage_woocommerce' ) && in_array( get_current_user_id(), self::sellers( $order ), true ) && ! in_array( $to, self::vendor_statuses(), true ) && ! in_array( $to, array( 'cancelled' ), true ) ) {
			self::$internal = true; $order->update_status( $from, 'Status change not allowed for sellers — reverted. ' ); self::$internal = false; return;
		}
		// Keep the parent order in step with its seller sub-orders.
		if ( $order->get_parent_id() && in_array( $to, self::vendor_statuses(), true ) ) {
			$p = wc_get_order( $order->get_parent_id() ); if ( ! $p || $p->has_status( array( 'sbh-returned', 'sbh-replacement', 'completed', 'cancelled', 'refunded' ) ) ) { return; }
			$same = true; foreach ( wc_get_orders( array( 'parent' => $p->get_id(), 'limit' => -1 ) ) as $k ) { if ( ! $k->has_status( $to ) ) { $same = false; break; } }
			if ( $same && ! $p->has_status( $to ) ) { self::$internal = true; $p->update_status( $to ); self::$internal = false; }
		}
	}
	public static function on_shipped( $id, $o ) {
		$o = $o ?: wc_get_order( $id ); $o->update_meta_data( '_sbh_shipped_ts', time() ); $o->save_meta_data();
		if ( $o->get_parent_id() ) { return; }
		self::mail( $o->get_billing_email(), 'Your order #' . $o->get_order_number() . ' has been shipped', 'Your order is on its way', '<p>Good news — your order <strong>#' . esc_html( $o->get_order_number() ) . '</strong> has been shipped.</p><p>You can follow it from <a href="' . esc_url( $o->get_view_order_url() ) . '">your account</a>.</p>' );
	}
	public static function on_delivered( $id, $o ) {
		$o = $o ?: wc_get_order( $id ); $t = time(); $o->update_meta_data( '_sbh_delivered_ts', $t ); $o->save_meta_data();
		$h = self::holder( $o ); $d = self::data( $h );
		if ( $d && $d['status'] === 'replacement' ) { $d['status'] = 'replaced'; $d['log'][] = array( 't' => $t, 's' => 'replaced', 'by' => 'seller' ); self::save( $h, $d ); $h->add_order_note( 'REPLACEMENT DELIVERED.' ); }
		if ( $o->get_parent_id() ) { return; }
		$until = wp_date( 'j F Y', $t + self::DAYS * DAY_IN_SECONDS );
		$rep = $d && $d['status'] === 'replaced';
		self::mail( $o->get_billing_email(), 'Order #' . $o->get_order_number() . ' delivered', $rep ? 'Your replacement has been delivered' : 'Your order has been delivered', '<p>Your ' . ( $rep ? 'replacement for ' : '' ) . 'order <strong>#' . esc_html( $o->get_order_number() ) . '</strong> has been delivered.</p>' . ( $rep ? '<p>The order will be completed automatically in 7 days.</p>' : '<p>If there is a problem, you can request a return or replacement from <a href="' . esc_url( $o->get_view_order_url() ) . '">your order page</a> until <strong>' . esc_html( $until ) . '</strong> (7 days from delivery). After that the order is completed automatically.</p>' ) );
	}

	/* ---------------------------------------------------------------- cancel */
	public static function cancel_statuses( $s, $o = null ) {
		if ( $o instanceof WC_Order ) { $c = $o->get_date_created(); if ( $c && time() > $c->getTimestamp() + self::CANCEL_HOURS * HOUR_IN_SECONDS ) { return array(); } }
		return array_values( array_unique( array_merge( (array) $s, array( 'pending', 'failed', 'on-hold', 'processing' ) ) ) );
	}
	public static function after_cancel( $order_id ) {
		$o = wc_get_order( $order_id ); if ( ! $o ) { return; }
		if ( $o->get_date_paid() && $o->get_payment_method() !== 'cod' ) {
			$o->add_order_note( 'Customer cancelled a PAID order. Please issue the refund from WooCommerce → Refund.' );
			self::mail( self::support(), 'Refund due — order #' . $o->get_order_number() . ' cancelled by customer', 'Refund due', '<p>The customer cancelled a paid order. Please issue the refund.</p><p><a href="' . esc_url( $o->get_edit_order_url() ) . '">Open order #' . esc_html( $o->get_order_number() ) . '</a></p>' );
		}
	}

	/* --------------------------------------------------------- data helpers */
	private static function holder( $o ) { return ( $o && $o->get_parent_id() ) ? ( wc_get_order( $o->get_parent_id() ) ?: $o ) : $o; }
	private static function data( $o ) { $d = $o ? $o->get_meta( self::META ) : null; return is_array( $d ) ? $d : array(); }
	private static function save( $o, $d ) { $o->update_meta_data( self::META, $d ); $o->update_meta_data( '_sbh_ret_status', $d['status'] ); $o->save(); }
	private static function base_ts( $o ) {
		$t = (int) $o->get_meta( '_sbh_delivered_ts' ); if ( $t ) { return $t; }
		if ( $o->has_status( 'completed' ) && $o->get_date_completed() ) { return $o->get_date_completed()->getTimestamp(); }
		return 0;
	}
	private static function deadline( $o ) { $t = self::base_ts( $o ); return $t ? $t + self::DAYS * DAY_IN_SECONDS : 0; }
	private static function eligible( $o ) {
		return $o && ( $o->has_status( 'sbh-delivered' ) || $o->has_status( 'completed' ) ) && ! self::data( $o ) && self::deadline( $o ) >= time();
	}
	private static function sellers( $o ) {
		$ids = array(); $o = self::holder( $o );
		$orders = wc_get_orders( array( 'parent' => $o->get_id(), 'limit' => -1 ) ); if ( ! $orders ) { $orders = array( $o ); }
		foreach ( $orders as $x ) {
			$v = function_exists( 'dokan_get_seller_id_by_order' ) ? (int) dokan_get_seller_id_by_order( $x->get_id() ) : 0;
			if ( ! $v ) { foreach ( $x->get_items() as $it ) { $v = (int) get_post_field( 'post_author', $it->get_product_id() ); if ( $v ) { break; } } }
			if ( $v ) { $ids[] = $v; }
		}
		return array_values( array_unique( $ids ) );
	}
	private static function vendor_url( $o ) {
		$kids = wc_get_orders( array( 'parent' => $o->get_id(), 'limit' => 1 ) ); $id = $kids ? $kids[0]->get_id() : $o->get_id();
		return function_exists( 'dokan_get_navigation_url' ) ? add_query_arg( 'order_id', $id, dokan_get_navigation_url( 'orders' ) ) : home_url( '/' );
	}
	private static function set_order_status( $h, $status, $note ) {
		self::$internal = true;
		$h->update_status( $status, $note );
		foreach ( wc_get_orders( array( 'parent' => $h->get_id(), 'limit' => -1 ) ) as $k ) { $k->update_status( $status, $note ); }
		self::$internal = false;
	}

	/* ---------------------------------------------------------------- emails */
	private static function mail( $to, $subject, $heading, $html ) {
		if ( ! $to || ! class_exists( 'WC_Emails' ) ) { return; }
		$m = WC()->mailer();
		$m->send( $to, $subject, $m->wrap_message( $heading, $html ), "Content-Type: text/html\r\n" );
	}
	private static function notify( $o, $subject, $c_html, $v_html, $a_html ) {
		$n = '#' . $o->get_order_number();
		self::mail( $o->get_billing_email(), "$subject — order $n", $subject, $c_html . '<p><a href="' . esc_url( $o->get_view_order_url() ) . '">View your order</a></p>' );
		foreach ( self::sellers( $o ) as $uid ) { $u = get_userdata( $uid ); if ( $u ) { self::mail( $u->user_email, "$subject — order $n", $subject, $v_html . '<p><a href="' . esc_url( self::vendor_url( $o ) ) . '">Open in your vendor dashboard</a></p>' ); } }
		$to = array_unique( array_filter( array( self::support(), get_option( 'admin_email' ) ) ) );
		foreach ( $to as $t ) { self::mail( $t, "$subject — order $n", $subject, $a_html . '<p><a href="' . esc_url( $o->get_edit_order_url() ) . '">Open order in admin</a></p>' ); }
	}
	/* $state: returned | replacement | rejected  ($how: accepted | refund (replacement unavailable) | auto) */
	private static function decide( $o, $state, $actor, $extra = array(), $how = 'accepted' ) {
		$d = self::data( $o ); $d['status'] = $state; $d['log'][] = array( 't' => time(), 's' => $state, 'by' => $actor );
		if ( $how === 'refund' ) { $d['type'] = 'return'; $d['converted'] = 1; }
		foreach ( $extra as $k => $v ) { $d[ $k ] = $v; }
		self::save( $o, $d );
		$n = '#' . $o->get_order_number(); $note = ! empty( $d['reason_admin'] ) ? '<p><strong>Note:</strong> ' . nl2br( esc_html( $d['reason_admin'] ) ) . '</p>' : ''; $ship = ! empty( $d['ship'] ) ? '<p><strong>Details:</strong><br>' . nl2br( esc_html( $d['ship'] ) ) . '</p>' : '';
		$by = $how === 'auto' ? 'automatically (seller did not respond within ' . self::VENDOR_DAYS . ' days)' : $actor;
		if ( $state === 'returned' ) {
			self::set_order_status( $o, 'sbh-returned', 'Return accepted — ' . $by . ( $how === 'refund' ? ' (replacement not available, refund offered)' : '' ) . '. Order marked Returned.' );
			$why = $how === 'refund' ? 'The replacement is not available, so the seller has offered a refund instead. ' : '';
			$msg = array( 'Your return is accepted', "<p>{$why}Your return request for order $n has been accepted ($by). The order is now marked <strong>Returned</strong>. Your refund will be processed as per our Refund &amp; Returns Policy (7–10 business days).</p>$ship$note", "<p>The return for order $n has been accepted ($by) and the order is marked Returned. The refund is handled by the marketplace.</p>$ship$note", "<p>Return for order $n accepted ($by). Order marked Returned — please issue the refund from the order screen.</p>$ship$note" );
		} elseif ( $state === 'replacement' ) {
			self::set_order_status( $o, 'sbh-replacement', 'Replacement accepted — ' . $by . '. Order marked Replacement.' );
			$msg = array( 'Your replacement is approved', "<p>Your replacement request for order $n has been accepted ($by). The seller will send your replacement soon; we will tell you when it is delivered.</p>$ship$note", "<p>Replacement for order $n accepted ($by). Please ship the replacement and set the order to <strong>Shipped</strong>, then <strong>Delivered</strong>.</p>$ship$note", "<p>Replacement for order $n accepted ($by). Order marked Replacement.</p>$ship$note" );
		} else {
			$o->add_order_note( 'REQUEST REJECTED — by ' . $by . ( ! empty( $d['reason_admin'] ) ? ': ' . $d['reason_admin'] : '' ) );
			$msg = array( 'Update on your request', "<p>We are sorry — the " . self::type( $d ) . " request for order $n could not be approved.</p>$note<p>Questions? Write to " . esc_html( self::support() ) . '.</p>', "<p>The " . self::type( $d ) . " request for order $n was rejected ($by).</p>$note", "<p>" . ucfirst( self::type( $d ) ) . " request for order $n rejected ($by).</p>$note" );
		}
		self::notify( $o, $msg[0], $msg[1], $msg[2], $msg[3] );
	}

	/* ------------------------------------------------------------------ cron */
	public static function cron() {
		$now = time();
		foreach ( wc_get_orders( array( 'limit' => -1, 'meta_key' => '_sbh_ret_status', 'meta_value' => 'requested' ) ) as $o ) {
			$d = self::data( $o ); if ( ! empty( $d['ts'] ) && $d['ts'] + self::VENDOR_DAYS * DAY_IN_SECONDS <= $now ) { self::decide( $o, self::type( $d ) === 'replacement' ? 'replacement' : 'returned', 'Auto', array( 'auto' => 1 ), 'auto' ); }
		}
		foreach ( wc_get_orders( array( 'limit' => -1, 'status' => 'sbh-delivered' ) ) as $o ) {
			$d = self::data( self::holder( $o ) ); $st = $d ? $d['status'] : '';
			if ( in_array( $st, array( '', 'rejected', 'replaced' ), true ) && self::deadline( $o ) && self::deadline( $o ) <= $now ) { self::$internal = true; $o->update_status( 'completed', 'Auto-completed: ' . self::DAYS . ' days after delivery with no open return/replacement.' ); self::$internal = false; }
		}
	}

	/* ------------------------------------------------------- customer pages */
	public static function order_actions( $actions, $o ) {
		if ( self::eligible( $o ) ) { $actions['sbh_return'] = array( 'url' => $o->get_view_order_url() . '#sbh-return', 'name' => 'Return / replace' ); }
		return $actions;
	}
	public static function list_status_extra( $o ) { $d = self::data( $o ); if ( $d ) { echo '<br><small class="sbh-pill sbh-pill-ret">' . esc_html( self::lbl( $d ) ) . '</small>'; } }
	public static function render_customer( $o ) {
		if ( ! $o || (int) $o->get_user_id() !== get_current_user_id() ) { return; }
		$d = self::data( $o ); $mail = '<a href="mailto:' . esc_attr( self::support() ) . '">' . esc_html( self::support() ) . '</a>';
		echo '<section id="sbh-return" class="sbh-card"><h2>Return or replacement</h2>';
		$fl = isset( $_GET['sbh_ret'] ) ? sanitize_key( $_GET['sbh_ret'] ) : '';
		$msgs = array( 'ok' => 'Your request has been submitted. We have emailed you a confirmation.', 'bad' => 'Please choose what you need, a reason, describe the issue and accept the declaration.', 'inel' => 'This order is not eligible for a return or replacement.' );
		if ( isset( $msgs[ $fl ] ) ) { echo '<p class="sbh-note"><strong>' . esc_html( $msgs[ $fl ] ) . '</strong></p>'; }
		if ( $d ) {
			$rep = self::type( $d ) === 'replacement'; $st = $d['status'];
			if ( $st !== 'rejected' ) {
				$steps = array( 'Requested', 'Accepted', $rep ? 'Replaced' : 'Returned' ); $cur = $st === 'requested' ? 0 : ( in_array( $st, array( 'returned', 'replaced' ), true ) ? 2 : 1 );
				echo '<ol class="sbh-steps">'; foreach ( $steps as $i => $s ) { echo '<li class="' . ( $i <= $cur ? 'on' : '' ) . '">' . esc_html( $s ) . '</li>'; } echo '</ol>';
			}
			echo '<p><strong>Status: ' . esc_html( self::lbl( $d ) ) . '.</strong> Reason: ' . esc_html( self::reasons()[ $d['reason'] ] ?? '' ) . '</p>';
			if ( $st === 'requested' ) { echo '<p>The seller has up to ' . self::VENDOR_DAYS . ' days (until ' . esc_html( wp_date( 'j M Y', $d['ts'] + self::VENDOR_DAYS * DAY_IN_SECONDS ) ) . ') to accept or reject. If there is no response, your request is accepted automatically.</p>'; }
			if ( ! empty( $d['converted'] ) ) { echo '<p>The replacement was not available, so the seller has offered a refund instead.</p>'; }
			if ( $st === 'returned' ) { echo '<p>Your return is accepted and the order is marked Returned. Your refund will be processed within 7–10 business days.</p>'; }
			if ( $st === 'replacement' ) { echo '<p>Your replacement is approved and will be shipped by the seller.</p>'; }
			if ( ! empty( $d['ship'] ) ) { echo '<p><strong>Details from the seller:</strong><br>' . nl2br( esc_html( $d['ship'] ) ) . '</p>'; }
			if ( $st === 'rejected' && ! empty( $d['reason_admin'] ) ) { echo '<p><strong>Reason:</strong> ' . esc_html( $d['reason_admin'] ) . '</p>'; }
		} elseif ( self::eligible( $o ) ) {
			echo '<p>You can ask for a return (refund) or a replacement within ' . self::DAYS . ' days of delivery (until ' . esc_html( wp_date( 'j F Y', self::deadline( $o ) ) ) . '). Items must be unused and in original condition with packaging intact. Perishable and opened food items are eligible only if damaged or incorrect on delivery.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sbh_return_request"><input type="hidden" name="order_id" value="' . (int) $o->get_id() . '">';
			wp_nonce_field( 'sbh_ret_' . $o->get_id(), 'sbh_nonce' );
			echo '<p><strong>What would you like?</strong><br><label><input type="radio" name="type" value="return" required> Return &amp; refund</label> &nbsp; <label><input type="radio" name="type" value="replacement"> Replacement</label></p>';
			echo '<p><label><strong>Reason</strong><br><select name="reason" required><option value="">— Select a reason —</option>';
			foreach ( self::reasons() as $k => $v ) { echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>'; }
			echo '</select></label></p><p><label><strong>Tell us what happened</strong><br><textarea name="details" rows="4" required></textarea></label></p>';
			echo '<p class="sbh-decl"><label><input type="checkbox" name="decl" value="1" required> <strong>Declaration:</strong> I confirm that I have emailed a complete, clear unboxing video of this parcel (from the sealed package to opening, showing the issue) to <strong>' . esc_html( self::support() ) . '</strong> with my order number #' . esc_html( $o->get_order_number() ) . ', and that the product is unused and in original condition. I understand the request is accepted only after review by the seller / Sonar Bangla Haat.</label></p>';
			echo '<p><button type="submit" class="button alt">Submit request</button></p></form>';
		} elseif ( $o->has_status( array( 'sbh-delivered', 'completed' ) ) ) {
			echo '<p>The ' . self::DAYS . '-day window for this order has ended. For help, write to ' . $mail . '.</p>';
		} else {
			echo '<p>You can request a return or replacement after your order is delivered, within ' . self::DAYS . ' days. Orders can be cancelled from <em>My Orders</em> only within ' . self::CANCEL_HOURS . ' hours of placing them.</p>';
		}
		echo '</section>';
	}
	public static function handle_request() {
		$id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; $o = $id ? wc_get_order( $id ) : null;
		$back = $o ? $o->get_view_order_url() : wc_get_page_permalink( 'myaccount' );
		if ( ! is_user_logged_in() || ! $o || (int) $o->get_user_id() !== get_current_user_id() || ! isset( $_POST['sbh_nonce'] ) || ! wp_verify_nonce( $_POST['sbh_nonce'], 'sbh_ret_' . $id ) ) { wp_safe_redirect( $back ); exit; }
		$reason = isset( $_POST['reason'] ) ? sanitize_key( $_POST['reason'] ) : ''; $det = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';
		$type = ( isset( $_POST['type'] ) && $_POST['type'] === 'replacement' ) ? 'replacement' : ( ( isset( $_POST['type'] ) && $_POST['type'] === 'return' ) ? 'return' : '' ); $flag = '';
		if ( ! self::eligible( $o ) ) { $flag = 'inel'; }
		elseif ( $type === '' || ! isset( self::reasons()[ $reason ] ) || $det === '' || empty( $_POST['decl'] ) ) { $flag = 'bad'; }
		else {
			$n = '#' . $o->get_order_number(); $r = self::reasons()[ $reason ]; $due = wp_date( 'j M Y', time() + self::VENDOR_DAYS * DAY_IN_SECONDS ); $w = $type === 'replacement' ? 'replacement' : 'return';
			$d = array( 'status' => 'requested', 'type' => $type, 'reason' => $reason, 'details' => $det, 'decl_ts' => time(), 'ts' => time(), 'log' => array( array( 't' => time(), 's' => 'requested', 'by' => 'customer' ) ) );
			self::save( $o, $d );
			$o->add_order_note( strtoupper( $w ) . ' REQUESTED by customer — ' . $r . '. Unboxing-video declaration accepted. The seller has ' . self::VENDOR_DAYS . ' days to respond (until ' . $due . ') or it is auto-accepted.' );
			$body = '<p><strong>Request:</strong> ' . esc_html( ucfirst( $w ) ) . '<br><strong>Reason:</strong> ' . esc_html( $r ) . '<br><strong>Details:</strong> ' . nl2br( esc_html( $det ) ) . '</p>';
			self::notify( $o, ucfirst( $w ) . ' requested',
				"<p>We have received your $w request for order $n.</p>$body<p>The seller has " . self::VENDOR_DAYS . " days (until $due) to respond; if there is no response it is accepted automatically. Please make sure your unboxing video has been emailed to " . esc_html( self::support() ) . '.</p>',
				"<p>A customer has requested a $w for order $n.</p>$body<p><strong>Please accept or reject by $due.</strong> If you do not respond within " . self::VENDOR_DAYS . ' days, it is accepted automatically.</p>',
				"<p>$w request for order $n.</p>$body<p>The customer declared that the unboxing video was emailed to " . esc_html( self::support() ) . '. Seller must respond by ' . $due . '.</p>' );
			$flag = 'ok';
		}
		wp_safe_redirect( add_query_arg( 'sbh_ret', $flag, $back ) . '#sbh-return' ); exit;
	}

	/* --------------------------------------------------- admin & vendor panel */
	public static function add_box() {
		$screens = array( 'shop_order' ); if ( function_exists( 'wc_get_page_screen_id' ) ) { $screens[] = wc_get_page_screen_id( 'shop-order' ); }
		foreach ( array_unique( array_filter( $screens ) ) as $s ) { add_meta_box( 'sbh_return', 'Return / replacement', array( __CLASS__, 'render_admin' ), $s, 'side', 'high' ); }
	}
	public static function render_admin( $post ) {
		$o = wc_get_order( $post instanceof WP_Post ? $post->ID : $post->get_id() ); if ( ! $o ) { return; }
		self::panel( self::holder( $o ) );
	}
	public static function render_vendor( $order ) {
		$o = is_object( $order ) ? $order : wc_get_order( $order ); if ( ! $o ) { return; }
		$h = self::holder( $o ); if ( ! self::data( $h ) ) { return; }
		if ( ! in_array( get_current_user_id(), self::sellers( $h ), true ) ) { return; }
		echo '<div class="dokan-panel dokan-panel-default"><div class="dokan-panel-heading"><strong>Return / replacement request</strong></div><div class="dokan-panel-body">'; self::panel( $h ); echo '</div></div>';
	}
	private static function panel( $h ) {
		$d = self::data( $h ); if ( ! $d ) { echo '<p>No return or replacement request on this order.</p>'; return; }
		$st = $d['status']; $rep = self::type( $d ) === 'replacement'; $id = (int) $h->get_id(); $nonce = wp_create_nonce( 'sbh_dec_' . $id ); $url = admin_url( 'admin-post.php?action=sbh_return_decide' );
		echo '<p><strong>' . esc_html( self::lbl( $d ) ) . '</strong>' . ( ! empty( $d['auto'] ) ? ' (auto-accepted)' : '' ) . '</p>';
		echo '<p><strong>Customer wants:</strong> ' . ( $rep ? 'Replacement' : 'Return &amp; refund' ) . '<br><strong>Reason:</strong> ' . esc_html( self::reasons()[ $d['reason'] ] ?? '' ) . '<br><strong>Customer says:</strong> ' . nl2br( esc_html( $d['details'] ) ) . '<br><strong>Unboxing-video declaration:</strong> accepted ' . esc_html( wp_date( 'j M Y, H:i', $d['decl_ts'] ) ) . '<br><em>Check that the video reached ' . esc_html( self::support() ) . '.</em></p>';
		if ( $st !== 'requested' ) { if ( ! empty( $d['ship'] ) ) { echo '<p><strong>Details sent:</strong> ' . nl2br( esc_html( $d['ship'] ) ) . '</p>'; } return; }
		echo '<p><strong>Respond by ' . esc_html( wp_date( 'j M Y, H:i', $d['ts'] + self::VENDOR_DAYS * DAY_IN_SECONDS ) ) . '</strong> — otherwise it is accepted automatically.</p>';
		$btn = function ( $a, $m, $label, $primary = false ) use ( $id, $nonce, $url ) { return '<button type="button" class="button' . ( $primary ? ' button-primary dokan-btn dokan-btn-success' : ' dokan-btn' ) . '" onclick="sbhRet(' . esc_attr( wp_json_encode( array( $id, $nonce, $a, $m, $url ) ) ) . ')">' . esc_html( $label ) . '</button> '; };
		echo '<p><label>Message / pickup or shipping details for the customer (optional)<br><textarea id="sbh_rship" rows="3" style="width:100%"></textarea></label></p><p><label>Reason (required when rejecting)<br><textarea id="sbh_rnote" rows="2" style="width:100%"></textarea></label></p><p>';
		if ( $rep ) { echo $btn( 'accept', 'Accept the replacement request and notify the customer?', 'Accept replacement', true ) . $btn( 'refund', 'Replacement is not available — offer a refund instead (order becomes Returned)?', 'Replacement not available — offer refund' ); }
		else { echo $btn( 'accept', 'Accept this return (order becomes Returned) and notify the customer?', 'Accept return', true ); }
		echo $btn( 'reject', 'Reject this request and notify the customer?', 'Reject' ) . '</p>';
		static $once = false; if ( ! $once ) { $once = true;
			echo '<script>window.sbhRet=window.sbhRet||function(a){if(!confirm(a[3]))return;var f=document.createElement("form");f.method="post";f.action=a[4];var v={sbh_order:String(a[0]),sbh_nonce:a[1],sbh_action:a[2],sbh_ship:(document.getElementById("sbh_rship")||{}).value||"",sbh_note:(document.getElementById("sbh_rnote")||{}).value||""};for(var k in v){var i=document.createElement("input");i.type="hidden";i.name=k;i.value=v[k];f.appendChild(i);}document.body.appendChild(f);f.submit();};</script>'; }
	}
	public static function handle_decide() {
		$id = isset( $_POST['sbh_order'] ) ? absint( $_POST['sbh_order'] ) : 0; $o = $id ? wc_get_order( $id ) : null;
		if ( ! $o || ! isset( $_POST['sbh_nonce'] ) || ! wp_verify_nonce( $_POST['sbh_nonce'], 'sbh_dec_' . $id ) ) { wp_die( 'Not allowed.', 403 ); }
		$admin = current_user_can( 'manage_woocommerce' );
		if ( ! $admin && ! in_array( get_current_user_id(), self::sellers( $o ), true ) ) { wp_die( 'Not allowed.', 403 ); }
		$d = self::data( $o ); if ( ! $d || $d['status'] !== 'requested' ) { wp_die( 'This request has already been handled.' ); }
		$actor = $admin ? 'Admin' : 'Seller'; $act = sanitize_key( $_POST['sbh_action'] ?? '' ); $rep = self::type( $d ) === 'replacement';
		$ship = sanitize_textarea_field( wp_unslash( $_POST['sbh_ship'] ?? '' ) ); $note = sanitize_textarea_field( wp_unslash( $_POST['sbh_note'] ?? '' ) );
		if ( $act === 'accept' ) { self::decide( $o, $rep ? 'replacement' : 'returned', $actor, array( 'ship' => $ship, 'reason_admin' => $note ) ); }
		elseif ( $act === 'refund' && $rep ) { self::decide( $o, 'returned', $actor, array( 'ship' => $ship, 'reason_admin' => $note ), 'refund' ); }
		elseif ( $act === 'reject' ) { if ( $note === '' ) { wp_die( 'Please go back and enter a reason for rejecting.' ); } self::decide( $o, 'rejected', $actor, array( 'reason_admin' => $note ) ); }
		$back = $admin ? $o->get_edit_order_url() : ( wp_get_referer() ?: self::vendor_url( $o ) );
		wp_safe_redirect( $back ); exit;
	}

	/* ------------------------------------------------------- admin list column */
	public static function col( $c ) { $c['sbh_return'] = 'Return / replace'; return $c; }
	public static function col_val( $col, $o = null ) {
		if ( $col !== 'sbh_return' ) { return; }
		if ( ! is_object( $o ) ) { global $post; $o = $post ? wc_get_order( $post->ID ) : null; } elseif ( ! ( $o instanceof WC_Order ) ) { $o = wc_get_order( $o ); }
		$d = $o ? self::data( self::holder( $o ) ) : array(); echo $d ? '<mark class="order-status"><span>' . esc_html( self::lbl( $d ) ) . '</span></mark>' : '—';
	}

	/* ---------------------------------------------- emails & account experience */
	public static function cod_subject( $s, $o ) { return ( $o && $o->get_payment_method() === 'cod' ) ? 'Order Received – Order #' . $o->get_order_number() . ' | Sonar Bangla Haat' : $s; }
	public static function cod_heading( $h, $o ) { return ( $o && $o->get_payment_method() === 'cod' ) ? 'Thank You! We Have Received Your Order' : $h; }
	public static function body_class( $c ) { if ( function_exists( 'is_account_page' ) && is_account_page() && is_user_logged_in() && ! is_wc_endpoint_url() ) { $c[] = 'sbh-dash'; } return $c; }
	public static function dashboard() {
		$u = wp_get_current_user(); $name = trim( $u->first_name ?: $u->display_name ) ?: 'there';
		$last = wc_get_orders( array( 'customer' => $u->ID, 'limit' => 1, 'parent' => 0 ) );
		echo '<div class="sbh-dashbox"><h2>Welcome back, ' . esc_html( $name ) . '</h2><p class="sbh-sub">Everything about your orders and account in one place.</p>';
		if ( $last ) { $l = $last[0]; echo '<a class="sbh-latest" href="' . esc_url( $l->get_view_order_url() ) . '"><span>Latest order #' . esc_html( $l->get_order_number() ) . '</span><b class="sbh-pill">' . esc_html( wc_get_order_status_name( $l->get_status() ) ) . '</b></a>'; }
		echo '<div class="sbh-tiles">';
		$tiles = array( array( 'Orders', 'Track, cancel or return', wc_get_account_endpoint_url( 'orders' ), '📦' ), array( 'Addresses', 'Billing &amp; shipping', wc_get_account_endpoint_url( 'edit-address' ), '🏠' ), array( 'Account details', 'Name, email, password', wc_get_account_endpoint_url( 'edit-account' ), '👤' ), array( 'Help &amp; support', esc_html( self::support() ), 'mailto:' . self::support(), '💬' ) );
		foreach ( $tiles as $t ) { echo '<a class="sbh-tile" href="' . esc_url( $t[2] ) . '"><strong>' . $t[0] . '</strong><span>' . $t[1] . '</span></a>'; }
		echo '</div></div>';
	}
	public static function css() {
		echo '<style id="sbh-ux">
.sbh-card{margin:28px 0;padding:22px;border:1px solid #eadfd6;border-radius:12px;background:#fffaf5}.sbh-card h2{margin-top:0}.sbh-card select,.sbh-card textarea{width:100%;max-width:520px}
.sbh-note{padding:10px 14px;background:#f1f8f1;border-left:4px solid #5a9a5a;border-radius:4px}.sbh-decl{padding:12px;background:#fff;border:1px dashed #c9a27e;border-radius:8px}
.sbh-steps{list-style:none;display:flex;gap:6px;padding:0;margin:0 0 14px}.sbh-steps li{flex:1;text-align:center;padding:8px 4px;background:#efe6df;color:#7a6a5f;font-size:13px;border-radius:6px}.sbh-steps li.on{background:#b4531f;color:#fff;font-weight:600}
.sbh-pill{display:inline-block;padding:3px 10px;border-radius:20px;background:#fdebdc;color:#9a4413;font-size:12px;font-weight:600}.sbh-pill-ret{background:#fff0c9;color:#7a5a00;margin-top:4px}
.woocommerce-account .woocommerce-MyAccount-navigation ul{list-style:none;margin:0;padding:8px;background:#fff;border:1px solid #eadfd6;border-radius:12px;box-shadow:0 2px 12px rgba(80,50,20,.06)}
.woocommerce-account .woocommerce-MyAccount-navigation li{border:0!important;margin:0}.woocommerce-account .woocommerce-MyAccount-navigation li a{display:block;padding:12px 14px;border-radius:8px;font-weight:600;color:#3b2a20;transition:.15s}
.woocommerce-account .woocommerce-MyAccount-navigation li a:hover,.woocommerce-account .woocommerce-MyAccount-navigation li.is-active a{background:#fff1e6;color:#b4531f}
.woocommerce-account .woocommerce-MyAccount-content{background:#fff;border:1px solid #eadfd6;border-radius:12px;padding:26px 28px;box-shadow:0 2px 12px rgba(80,50,20,.06)}
.woocommerce-account .woocommerce-MyAccount-content h2,.woocommerce-account .woocommerce-MyAccount-content h3{font-size:20px;margin-top:0}
.woocommerce-account table.shop_table{border:1px solid #eadfd6;border-radius:10px;border-collapse:separate;border-spacing:0;overflow:hidden}.woocommerce-account table.shop_table th{background:#faf4ef;font-weight:700}
.woocommerce-account table.shop_table td,.woocommerce-account table.shop_table th{padding:12px 14px;border-color:#f0e7e0}
.woocommerce-account .woocommerce-button,.woocommerce-account a.button,.woocommerce-account button.button,.woocommerce-account .button.alt{background:#b4531f!important;color:#fff!important;border-radius:8px!important;padding:10px 18px!important;border:0!important;font-weight:600}
.woocommerce-account .woocommerce-button:hover,.woocommerce-account .button:hover{background:#963f12!important}
.woocommerce-account input.input-text,.woocommerce-account select,.woocommerce-account textarea{border:1px solid #dccfc5;border-radius:8px;padding:10px 12px}
.woocommerce form .password-input{position:relative;display:block}.woocommerce form .password-input input{padding-right:64px}
.woocommerce form .show-password-input{position:absolute!important;right:10px;top:50%;transform:translateY(-50%);width:auto!important;height:auto!important;min-width:0;padding:4px 8px!important;border:0!important;background:transparent!important;color:#b4531f!important;font-size:12px;cursor:pointer;box-shadow:none!important}
.woocommerce form .show-password-input::after{content:"Show";font-family:inherit;font-size:12px;font-weight:600}.woocommerce form .show-password-input.display-password::after{content:"Hide"}
.woocommerce-password-strength{border-radius:6px;font-weight:600;padding:6px;margin-top:8px}.woocommerce-password-hint{display:block;margin-top:6px;font-size:12px;color:#6b5b50}
body.sbh-dash .woocommerce-MyAccount-content>p{display:none}
.sbh-dashbox h2{margin:0 0 4px!important}.sbh-sub{display:block!important;color:#7a6a5f;margin:0 0 16px}
.sbh-latest{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;background:#fffaf5;border:1px solid #eadfd6;border-radius:10px;margin-bottom:18px;color:#3b2a20;font-weight:600}
.sbh-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px}.sbh-tile{display:block;padding:18px;border:1px solid #eadfd6;border-radius:12px;background:#fff;color:#3b2a20;transition:.15s}
.sbh-tile:hover{border-color:#b4531f;box-shadow:0 4px 14px rgba(180,83,31,.12);transform:translateY(-2px)}.sbh-tile i{font-style:normal;font-size:26px;display:block;margin-bottom:6px}.sbh-tile strong{display:block}.sbh-tile span{font-size:13px;color:#7a6a5f;word-break:break-all}
.sbh-hide{display:none!important}
@media(min-width:769px){.woocommerce-account .woocommerce-MyAccount-navigation{width:26%}.woocommerce-account .woocommerce-MyAccount-content{width:70%}}
@media(max-width:768px){.woocommerce-account .woocommerce-MyAccount-navigation,.woocommerce-account .woocommerce-MyAccount-content{width:100%;float:none}.woocommerce-account .woocommerce-MyAccount-navigation ul{display:flex;overflow-x:auto;gap:4px}.woocommerce-account .woocommerce-MyAccount-navigation li a{white-space:nowrap}.woocommerce-account .woocommerce-MyAccount-content{padding:18px;margin-top:14px}.sbh-steps{flex-wrap:wrap}}
</style>';
	}
	public static function js() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) { return; }
		echo '<script>(function(){var c=document.querySelector(".woocommerce-MyAccount-content");if(!c)return;[].slice.call(c.querySelectorAll("*")).forEach(function(e){if(e.children.length<5&&/become a vendor/i.test(e.textContent)&&!e.classList.contains("sbh-hide")){var t=e;while(t.parentElement&&t.parentElement!==c)t=t.parentElement;t.classList.add("sbh-hide");}});var h=document.querySelector(".woocommerce-ResetPassword input[name=password_1]");if(h&&!document.querySelector(".woocommerce-password-hint-sbh")){var s=document.createElement("small");s.className="woocommerce-password-hint woocommerce-password-hint-sbh";s.textContent="Use at least 8 characters with letters and numbers.";(h.closest(".password-input")||h).insertAdjacentElement("afterend",s);}})();</script>';
	}
}
add_action( 'init', array( 'SBH_Returns', 'boot' ), 20 );
