<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Simple admin screen to store Google Business Profile (GBP) OAuth tokens
 * and identifiers. This does NOT run the OAuth flow; it lets you paste
 * tokens you’ve obtained elsewhere (e.g., OAuth Playground / internal tool).
 *
 * Option key: awesome_reviews_gbp
 * Keys: account_id, location_id, access_token, access_expires_at (unix ts),
 *       refresh_token, client_id, client_secret
 */

function awesome_reviews_gbp_get_option(){
	$gbp = get_option('awesome_reviews_gbp', []);
	return is_array($gbp) ? $gbp : [];
}

function awesome_reviews_gbp_update_option($gbp){
	if (!is_array($gbp)) $gbp = [];
	update_option('awesome_reviews_gbp', $gbp);
}

function awesome_reviews_render_gbp_page(){
	if ( ! current_user_can('manage_options') ) return;

	$gbp = awesome_reviews_gbp_get_option();
	$notice = '';

	/* Save */
	if ( isset($_POST['arw_gbp_save']) ) {
		$ok = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'arw_gbp_save');
		if ( ! $ok ) {
			$notice = '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
		} else {
			$gbp = [
				'account_id'         => sanitize_text_field($_POST['account_id'] ?? ''),
				'location_id'        => sanitize_text_field($_POST['location_id'] ?? ''),
				'access_token'       => sanitize_text_field($_POST['access_token'] ?? ''),
				'access_expires_at'  => (int) ($_POST['access_expires_at'] ?? 0),
				'refresh_token'      => sanitize_text_field($_POST['refresh_token'] ?? ''),
				'client_id'          => sanitize_text_field($_POST['client_id'] ?? ''),
				'client_secret'      => sanitize_text_field($_POST['client_secret'] ?? ''),
			];
			awesome_reviews_gbp_update_option($gbp);
			$notice = '<div class="notice notice-success"><p>Saved.</p></div>';
		}
	}

	$help = 'Paste your Google Business Profile OAuth tokens. If only an access token is set, the plugin will use it until expiry. If a refresh token + client credentials are set and the token is expired, the plugin will refresh it automatically when rendering.';

	?>
	<div class="wrap">
		<h1>Reviews – Google Business Profile (OAuth)</h1>
		<?php echo $notice; ?>
		<p class="description" style="max-width:780px"><?php echo esc_html($help); ?></p>

		<form method="post">
			<?php wp_nonce_field('arw_gbp_save'); ?>
			<input type="hidden" name="arw_gbp_save" value="1">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="account_id">Account ID</label></th>
					<td><input type="text" name="account_id" id="account_id" class="regular-text" value="<?php echo esc_attr($gbp['account_id'] ?? ''); ?>" placeholder="accounts/1234567890"></td>
				</tr>
				<tr>
					<th scope="row"><label for="location_id">Location ID</label></th>
					<td><input type="text" name="location_id" id="location_id" class="regular-text" value="<?php echo esc_attr($gbp['location_id'] ?? ''); ?>" placeholder="locations/9876543210"></td>
				</tr>
				<tr>
					<th scope="row"><label for="access_token">Access token</label></th>
					<td><textarea name="access_token" id="access_token" class="large-text code" rows="3" placeholder="ya29...."><?php echo esc_textarea($gbp['access_token'] ?? ''); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="access_expires_at">Access token expires at (UNIX)</label></th>
					<td><input type="number" name="access_expires_at" id="access_expires_at" class="regular-text" value="<?php echo esc_attr((string)($gbp['access_expires_at'] ?? 0)); ?>" placeholder="<?php echo time()+3000; ?>">
						<p class="description">Unix timestamp; leave 0 if unknown.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="refresh_token">Refresh token</label></th>
					<td><input type="text" name="refresh_token" id="refresh_token" class="large-text" value="<?php echo esc_attr($gbp['refresh_token'] ?? ''); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="client_id">OAuth Client ID</label></th>
					<td><input type="text" name="client_id" id="client_id" class="large-text" value="<?php echo esc_attr($gbp['client_id'] ?? ''); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="client_secret">OAuth Client Secret</label></th>
					<td><input type="text" name="client_secret" id="client_secret" class="large-text" value="<?php echo esc_attr($gbp['client_secret'] ?? ''); ?>"></td>
				</tr>
			</table>

			<?php submit_button('Save GBP Settings'); ?>
		</form>
	</div>
	<?php
}

/**
 * Helper: ensure a valid GBP access token (refresh if expired and we can).
 * Returns a token string or ''.
 */
function awesome_reviews_gbp_get_access_token(){
	$gbp = awesome_reviews_gbp_get_option();
	$now = time();

	$token   = (string)($gbp['access_token'] ?? '');
	$expires = (int)($gbp['access_expires_at'] ?? 0);

	// Still valid?
	if ( $token !== '' && ( $expires === 0 || $expires > ($now + 60) ) ) {
		return $token;
	}

	// Try refresh if we can
	$refresh = (string)($gbp['refresh_token'] ?? '');
	$cid     = (string)($gbp['client_id'] ?? '');
	$csec    = (string)($gbp['client_secret'] ?? '');

	if ( $refresh !== '' && $cid !== '' && $csec !== '' ) {
		$body = [
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
			'client_id'     => $cid,
			'client_secret' => $csec,
		];
		$r = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			['timeout'=>15,'body'=>$body]
		);
		if ( ! is_wp_error($r) ) {
			$code = wp_remote_retrieve_response_code($r);
			$js   = json_decode( wp_remote_retrieve_body($r), true );
			if ( $code >= 200 && $code < 300 && is_array($js) && ! empty($js['access_token']) ) {
				$gbp['access_token']      = (string)$js['access_token'];
				$gbp['access_expires_at'] = $now + (int)($js['expires_in'] ?? 3600);
				update_option('awesome_reviews_gbp', $gbp);
				return (string)$gbp['access_token'];
			}
		}
	}

	return '';
}

/** Convenience getters for identifiers */
function awesome_reviews_gbp_account_id(){ $gbp = awesome_reviews_gbp_get_option(); return trim((string)($gbp['account_id'] ?? '')); }
function awesome_reviews_gbp_location_id(){ $gbp = awesome_reviews_gbp_get_option(); return trim((string)($gbp['location_id'] ?? '')); }
