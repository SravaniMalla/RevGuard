<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Reviews → Sources
 * Stores rows in option "awesome_reviews_sources".
 * Row format:
 *  ['id','provider' (google|yelp|facebook|all),'label','input','serp_api_key']
 */
function awesome_reviews_render_sources_page() {
	if ( ! current_user_can('manage_options') ) return;

	$providers = ['google','yelp','facebook','all'];
	$names     = ['google'=>'Google','yelp'=>'Yelp','facebook'=>'Facebook','all'=>'All'];

	/* Save */
	if ( isset($_POST['ar_sources_save']) && check_admin_referer('ar_sources_save','ar_sources_nonce') ) {
		$rows = [];
		$pvd  = $_POST['provider'] ?? [];
		$lbl  = $_POST['label'] ?? [];
		$inp  = $_POST['input'] ?? [];
		$serp = $_POST['serp_api_key'] ?? [];
		$del  = $_POST['delete'] ?? [];
		$ids  = $_POST['row_id'] ?? [];
		$n = max(count($pvd),count($lbl),count($inp),count($serp),count($ids));

		for ($i=0;$i<$n;$i++){
			$provider = strtolower( sanitize_text_field($pvd[$i] ?? '') );
			if ( ! in_array($provider,$providers,true) ) continue;
			if ( ! empty($del[$i]) ) continue;

			$id = sanitize_text_field($ids[$i] ?? '');
			if ( $id === '' ) $id = 'src_' . substr( wp_hash(microtime(true).$i.wp_rand()), 0, 7 );

			$rows[] = [
				'id'            => $id,
				'provider'      => $provider,
				'label'         => sanitize_text_field($lbl[$i] ?? ''),
				'input'         => sanitize_text_field($inp[$i] ?? ''),
				'serp_api_key'  => sanitize_text_field($serp[$i] ?? ''),
			];
		}
		update_option('awesome_reviews_sources',$rows);
		echo '<div class="notice notice-success"><p>Sources saved.</p></div>';
	}

	$list = get_option('awesome_reviews_sources',[]);
	if ( ! is_array($list) ) $list = [];

	$prov_options = function($sel='google') use ($providers,$names){
		$out = '';
		foreach ($providers as $p) $out .= '<option value="'.$p.'" '.selected($sel,$p,false).'>'.$names[$p].'</option>';
		return $out;
	};
	?>
	<div class="wrap">
		<h1>Reviews – Sources</h1>
		<p>Add your business sources. Enter only a <strong>Google Maps URL</strong>; the plugin will derive Google place_id and map Yelp automatically. Provider is used for quick-pick and aggregation.</p>

		<form method="post">
			<?php wp_nonce_field('ar_sources_save','ar_sources_nonce'); ?>
			<input type="hidden" name="ar_sources_save" value="1" />

			<table class="widefat fixed striped" id="ar-sources-table">
				<thead><tr>
					<th style="width:160px">Provider</th>
					<th style="width:240px">Label (optional)</th>
					<th>Google Maps URL</th>
					<th style="width:240px">SerpApi key</th>
					<th style="width:90px">Remove</th>
				</tr></thead>
				<tbody>
				<?php if ( empty($list) ) : ?>
					<tr>
						<td>
							<select name="provider[]" class="arw-input"><?php echo $prov_options('google'); ?></select>
							<input type="hidden" name="row_id[]" value="">
						</td>
						<td><input type="text" name="label[]" class="regular-text" placeholder="e.g., Main St Salon"></td>
						<td><input type="text" name="input[]" class="large-text" placeholder="https://maps.app.goo.gl/..."></td>
						<td><input type="text" name="serp_api_key[]" class="regular-text" placeholder="SerpApi key (optional)"></td>
						<td style="text-align:center"><label><input type="checkbox" name="delete[]"> Delete</label></td>
					</tr>
				<?php else: foreach ($list as $row) : ?>
					<tr>
						<td>
							<select name="provider[]" class="arw-input"><?php echo $prov_options($row['provider'] ?? 'google'); ?></select>
							<input type="hidden" name="row_id[]" value="<?php echo esc_attr($row['id'] ?? ''); ?>">
						</td>
						<td><input type="text" name="label[]" class="regular-text" value="<?php echo esc_attr($row['label'] ?? ''); ?>"></td>
						<td><input type="text" name="input[]" class="large-text" value="<?php echo esc_attr($row['input'] ?? ''); ?>"></td>
						<td><input type="text" name="serp_api_key[]" class="regular-text" value="<?php echo esc_attr($row['serp_api_key'] ?? ''); ?>"></td>
						<td style="text-align:center"><label><input type="checkbox" name="delete[]"> Delete</label></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<p style="margin-top:10px">
				<button type="button" class="button" id="ar-add-row">Add Row</button>
				<button type="submit" class="button button-primary">Save Sources</button>
			</p>
		</form>
	</div>

	<script>
	(function(){
	  const tbody = document.querySelector('#ar-sources-table tbody');
	  document.getElementById('ar-add-row').addEventListener('click', function(){
	    const tr = document.createElement('tr');
	    tr.innerHTML =
	      '<td><select name="provider[]" class="arw-input">' +
	      '<option value="google">Google</option>' +
	      '<option value="yelp">Yelp</option>' +
	      '<option value="facebook">Facebook</option>' +
	      '<option value="all">All</option>' +
	      '</select><input type="hidden" name="row_id[]" value=""></td>' +
	      '<td><input type="text" name="label[]" class="regular-text"></td>' +
	      '<td><input type="text" name="input[]" class="large-text"></td>' +
	      '<td><input type="text" name="serp_api_key[]" class="regular-text"></td>' +
	      '<td style="text-align:center"><label><input type="checkbox" name="delete[]"> Delete</label></td>';
	    tbody.appendChild(tr);
	  });
	})();
	</script>
	<?php
}
