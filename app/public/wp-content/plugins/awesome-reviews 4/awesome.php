<?php
/**
 * Plugin Name: Awesome Reviews (Google / Yelp / Facebook)
 * Description: Display business reviews from Google, Yelp and Facebook. Includes Sources management, a 5-step Widget Builder, Gutenberg render + shortcode, AI Responses, and SMTP email sending.
 * Version: 0.3.3
 * Author: Your Name
 * Text Domain: awesome-reviews
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------------- *
 * Constants
 * ------------------------------------------------------------------------- */
define( 'AWESOME_REVIEWS_VERSION', '0.3.3' );
define( 'AWESOME_REVIEWS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AWESOME_REVIEWS_URL', plugin_dir_url( __FILE__ ) );

/* ------------------------------------------------------------------------- *
 * Activation sanity checks (non-fatal; show an admin notice)
 * ------------------------------------------------------------------------- */
register_activation_hook( __FILE__, function () {
	$issues = [];
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) $issues[] = 'PHP 7.4+ is required.';
	foreach ( [
		'src/awesome/render.php',
		'src/awesome/admin-wizard.php',
		'src/awesome/admin-sources.php',
		'src/awesome/admin-ai.php'
	] as $rel ) {
		if ( ! file_exists( AWESOME_REVIEWS_DIR . $rel ) ) $issues[] = 'Missing: <code>'.$rel.'</code>';
	}
	if ( $issues ) set_transient( 'awesome_reviews_activation_notice', implode('<br>', $issues), 60 );
});

add_action( 'admin_notices', function () {
	if ( $msg = get_transient( 'awesome_reviews_activation_notice' ) ) {
		delete_transient( 'awesome_reviews_activation_notice' );
		echo '<div class="notice notice-warning"><p><strong>Awesome Reviews:</strong><br>'.wp_kses_post($msg).'</p></div>';
	}
});

/* ------------------------------------------------------------------------- *
 * Global settings helper
 * ------------------------------------------------------------------------- */
function awesome_reviews_get_settings() {
	$defaults = [
		'serpapi_key'            => '',
		'google_api_key'         => '',
		'google_place_id'        => '',
		'yelp_api_key'           => '',
		'yelp_business_id'       => '',
		'facebook_access_token'  => '',
		'facebook_page_id'       => '',
		'cache_ttl'              => 21600, // 6h
		'max_reviews_default'    => 9,
	];
	$opt = get_option('awesome_reviews_settings', []);
	if ( ! is_array($opt) ) $opt = [];
	return array_merge($defaults, $opt);
}

/* ------------------------------------------------------------------------- *
 * Renderer loader (file returns a callable)
 * ------------------------------------------------------------------------- */
function awesome_reviews_get_renderer() {
	static $cb = null;
	if ( $cb ) return $cb;
	$file = AWESOME_REVIEWS_DIR . 'src/awesome/render.php';
	if ( file_exists( $file ) ) {
		$cb = require $file;
		if ( is_callable( $cb ) ) return $cb;
	}
	$cb = function() { return '<em>Awesome Reviews: missing <code>render.php</code>.</em>'; };
	return $cb;
}

/* ------------------------------------------------------------------------- *
 * Gutenberg block (optional; if build exists)
 * ------------------------------------------------------------------------- */
add_action( 'init', function () {
	$block_dir  = AWESOME_REVIEWS_DIR . 'build/awesome';
	$block_json = $block_dir . '/block.json';
	if ( file_exists( $block_json ) ) {
		register_block_type( $block_dir, [
			'render_callback' => function( $attributes, $content ){
				$cb = awesome_reviews_get_renderer();
				return $cb( $attributes, $content );
			},
		] );
	}
});

/* ------------------------------------------------------------------------- *
 * REST helper: list saved sources for block UI (optional)
 * ------------------------------------------------------------------------- */
add_action( 'rest_api_init', function(){
	register_rest_route('awesome-reviews/v1','/sources',[
		'methods' => 'GET',
		'permission_callback' => fn() => current_user_can('edit_posts'),
		'callback' => function(){
			$rows = get_option('awesome_reviews_sources',[]);
			return is_array($rows) ? array_values($rows) : [];
		}
	]);
});

/* ------------------------------------------------------------------------- *
 * Include Admin pages (Sources + Wizard + GBP + AI)
 * ------------------------------------------------------------------------- */
require_once AWESOME_REVIEWS_DIR . 'src/awesome/admin-sources.php';
require_once AWESOME_REVIEWS_DIR . 'src/awesome/admin-wizard.php';
require_once AWESOME_REVIEWS_DIR . 'src/awesome/admin-ai.php'; // AI Responses page (and AI utils)

// Optional GBP page (only if present in your project)
if ( file_exists( AWESOME_REVIEWS_DIR . 'src/awesome/admin-gbp.php' ) ) {
	require_once AWESOME_REVIEWS_DIR . 'src/awesome/admin-gbp.php';
}

/* ------------------------------------------------------------------------- *
 * Admin menu
 * ------------------------------------------------------------------------- */
function awesome_reviews_menu_overview_cb(){
	echo '<div class="wrap"><h1>Reviews</h1><p>1) Add items under <strong>Sources</strong>. 2) Build a widget in <strong>Widget Builder</strong>. 3) Paste the <code>arw:wd_xxxxxx</code> into the block or use the shortcode.</p></div>';
}

function awesome_reviews_register_menus(){
	add_menu_page(
		'Reviews','Reviews','manage_options','awesome-reviews',
		'awesome_reviews_menu_overview_cb','dashicons-star-filled',60
	);

	add_submenu_page('awesome-reviews','Overview','Overview','manage_options','awesome-reviews','awesome_reviews_menu_overview_cb');

	add_submenu_page('awesome-reviews','Sources','Sources','manage_options','awesome-reviews-sources','awesome_reviews_render_sources_page');

	add_submenu_page('awesome-reviews','Widget Builder','Widget Builder','manage_options','awesome-reviews-wizard','awesome_reviews_render_wizard_page');

	add_submenu_page('awesome-reviews','AI Responses','AI Responses','manage_options','awesome-reviews-ai','awesome_reviews_render_ai_responses_page');

	add_submenu_page('awesome-reviews','Email Test','Email Test','manage_options','awesome-reviews-email-test','awesome_reviews_email_test_page');

	if ( function_exists('awesome_reviews_render_gbp_page') ) {
		add_submenu_page('awesome-reviews','Google Business Profile (OAuth)','Google BP (OAuth)','manage_options','awesome-reviews-gbp','awesome_reviews_render_gbp_page');
	}
}
add_action('admin_menu','awesome_reviews_register_menus',9);

/* ------------------------------------------------------------------------- *
 * Shortcode
 * ------------------------------------------------------------------------- */
add_shortcode('awesome_reviews', function($atts){
	$atts = shortcode_atts([
		'widget'     => '',
		'place_id'   => '',
		'api_key'    => '',
		'limit'      => '',
		'min_rating' => '',
		'language'   => '',
	],$atts,'awesome_reviews');

	$cb = awesome_reviews_get_renderer();

	if ( ! empty($atts['widget']) ) {
		return $cb(['widgetMode'=>'custom','customWidgetId'=>(string)$atts['widget']],'');
	}

	if ( ! empty($atts['place_id']) && ! empty($atts['api_key']) ) {
		$args = [
			'source'     => 'prov:google',
			'google_api' => (string) $atts['api_key'],
			'limit'      => (int) ($atts['limit'] !== '' ? $atts['limit'] : 5),
			'minRating'  => (int) ($atts['min_rating'] !== '' ? $atts['min_rating'] : 1),
			'language'   => (string) ($atts['language'] !== '' ? $atts['language'] : 'en'),
			'__place_id' => (string) $atts['place_id'],
		];
		return $cb($args, '');
	}

	return $cb([], '');
});

/* ======================================================================== *
 * SMTP + robust mail helper + admin handler for "Send Email"
 * ======================================================================== */
add_action('phpmailer_init', function($phpmailer){
	if (defined('AR_SMTP_HOST') && AR_SMTP_HOST) {
		$phpmailer->isSMTP();
		$phpmailer->Host       = AR_SMTP_HOST;
		$phpmailer->Port       = defined('AR_SMTP_PORT') ? AR_SMTP_PORT : 587;
		$phpmailer->SMTPSecure = defined('AR_SMTP_SECURE') ? AR_SMTP_SECURE : 'tls';
		$phpmailer->SMTPAuth   = (bool) (defined('AR_SMTP_USER') && AR_SMTP_USER);
		if ($phpmailer->SMTPAuth) {
			$phpmailer->Username = AR_SMTP_USER;
			$phpmailer->Password = AR_SMTP_PASS;
		}
		$from = (defined('AR_SMTP_FROM') && AR_SMTP_FROM) ? AR_SMTP_FROM : ('no-reply@' . parse_url(home_url(), PHP_URL_HOST));
		$phpmailer->setFrom($from, wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
		if (property_exists($phpmailer, 'Sender')) {
			$phpmailer->Sender = $from;
		}
	}
});

add_action('wp_mail_failed', function($wp_error){
	if (defined('WP_DEBUG') && WP_DEBUG) {
		error_log('[Awesome Reviews] Mail failed: ' . $wp_error->get_error_message());
	}
	set_transient('awesome_reviews_last_mail_error', $wp_error->get_error_message(), 600);
});

function awesome_reviews_send_reply_email($to, $subject, $message, $reply_to = '') {
	$to = sanitize_email($to);
	if ( ! is_email($to) ) {
		return new WP_Error('invalid_email','Recipient email is invalid.');
	}
	$headers = ['Content-Type: text/plain; charset=UTF-8'];
	if ($reply_to && is_email($reply_to)) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}
	$sent = wp_mail($to, $subject, $message, $headers);
	if ( ! $sent ) {
		$err = get_transient('awesome_reviews_last_mail_error');
		return new WP_Error('send_failed', $err ? $err : 'Unknown mail error (wp_mail returned false).');
	}
	return true;
}

/**
 * Handle final email send, then PERMANENTLY mark the review as "replied"
 * so it never shows up again on the AI Responses page.
 */
add_action('admin_post_arw_send_reply', function(){
	if ( ! current_user_can('manage_options') ) {
		wp_die('Unauthorized.', 403);
	}
	check_admin_referer('arw_send_reply');

	$to        = sanitize_text_field( wp_unslash($_POST['arw_email_to'] ?? '') );
	$subject   = sanitize_text_field( wp_unslash($_POST['arw_email_subject'] ?? '') );
	$message   = (string) wp_unslash($_POST['arw_email_message'] ?? '');
	$reply_to  = get_option('admin_email');

	// From the AI page
	$review_id = sanitize_text_field( wp_unslash($_POST['arw_review_id'] ?? '') );
	$widget_id = sanitize_text_field( wp_unslash($_POST['arw_widget_id'] ?? '') );

	$res = awesome_reviews_send_reply_email(
		$to,
		$subject ?: ('Reply to your review at ' . get_bloginfo('name')),
		$message,
		$reply_to
	);

	$redirect = !empty($_POST['redirect'])
		? esc_url_raw($_POST['redirect'])
		: admin_url('admin.php?page=awesome-reviews-ai');

	if ( is_wp_error($res) ) {
		set_transient('awesome_reviews_mail_notice', ['type'=>'error','text'=>'Email failed: '.$res->get_error_message()], 60);
	} else {
		// Permanently mark this review id as emailed
		$replied = get_option('awesome_reviews_ai_replied', []);
		if ( !is_array($replied) ) $replied = [];
		if ( $review_id ) {
			$replied[$review_id] = ['widget'=>$widget_id, 'time'=>time()];
			update_option('awesome_reviews_ai_replied', $replied);
		}
		set_transient('awesome_reviews_mail_notice', ['type'=>'success','text'=>'Email sent to '.$to], 60);
	}

	wp_safe_redirect($redirect);
	exit;
});

add_action('admin_notices', function(){
	if ( $msg = get_transient('awesome_reviews_mail_notice') ) {
		delete_transient('awesome_reviews_mail_notice');
		$cls = ($msg['type']==='error') ? 'notice-error' : 'notice-success';
		echo '<div class="notice '.esc_attr($cls).' is-dismissible"><p>'.esc_html($msg['text']).'</p></div>';
	}
});

/* ------------------------------------------------------------------------- *
 * Email Test page (submenu)
 * ------------------------------------------------------------------------- */
function awesome_reviews_email_test_page(){
	$sent_html = '';
	if ( isset($_POST['arw_email_test']) && check_admin_referer('arw_email_test') ) {
		$to = sanitize_email( $_POST['to'] ?? get_option('admin_email') );
		if ( function_exists('awesome_reviews_send_reply_email') ) {
			$res = awesome_reviews_send_reply_email(
				$to,
				'SMTP test',
				'Hello from WordPress (Awesome Reviews) via SMTP',
				get_option('admin_email')
			);
			if ( is_wp_error($res) ) {
				$sent_html = '<div class="notice notice-error"><p>Failed: ' .
					esc_html($res->get_error_message()) . '</p></div>';
			} else {
				$sent_html = '<div class="notice notice-success"><p>Sent to ' .
					esc_html($to) . '</p></div>';
			}
		} else {
			$ok = wp_mail( $to, 'SMTP test', 'Hello from WordPress (fallback)' );
			$sent_html = $ok
				? '<div class="notice notice-success"><p>Sent to ' . esc_html($to) . '</p></div>'
				: '<div class="notice notice-error"><p>Failed: wp_mail() returned false</p></div>';
		}
	}

	echo '<div class="wrap"><h1>Email Test</h1>';
	echo $sent_html;
	echo '<form method="post" style="max-width:520px">';
	wp_nonce_field('arw_email_test');
	echo '<p><label>Send to:&nbsp; <input type="email" name="to" class="regular-text" value="' .
		esc_attr( get_option('admin_email') ) . '" required></label></p>';
	echo '<p><button class="button button-primary" name="arw_email_test" value="1">Send test</button></p>';
	echo '<p class="description">If it fails, check <code>wp-content/debug.log</code> and Gmail security alerts. Ensure App Password and wp-config constants are correct.</p>';
	echo '</form></div>';
}
