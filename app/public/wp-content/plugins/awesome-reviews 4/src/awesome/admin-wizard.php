<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * 5-step Widget Builder (linked steps). Draft state per-user is kept in a transient.
 */

function arw_wiz_key(){ return 'arw_wiz_draft_' . get_current_user_id(); }
function arw_wiz_get(){ $d = get_transient(arw_wiz_key()); return is_array($d) ? $d : []; }
function arw_wiz_put($a){ set_transient(arw_wiz_key(), is_array($a)?$a:[], 3600); }
function arw_wiz_clear(){ delete_transient(arw_wiz_key()); }
function arw_wiz_url($step=1,$extra=[]){
	$step = max(1,min(5,(int)$step));
	return esc_url( add_query_arg( array_merge(['page'=>'awesome-reviews-wizard','step'=>$step],$extra), admin_url('admin.php') ) );
}

function arw_wiz_step_header($current){
	$steps=[1=>'Connect Source',2=>'Select Layout',3=>'Select Style',4=>'Set up widget',5=>'Insert code'];
	echo '<div class="arw-steps">';
	foreach($steps as $i=>$label){
		$cls='step'; if($i==$current)$cls.=' current'; elseif($i<$current)$cls.=' done';
		echo '<a class="'.$cls.'" href="'.arw_wiz_url($i).'"><span>'.$i.'</span> '.esc_html($label).'</a>';
		if($i<5) echo '<div class="caret">›</div>';
	}
	echo '</div>';
}

/* tiny fake preview for the gallery */
function arw_fake_reviews_html($theme='light',$count=3,$compact=false){
	$items=[
		['Lilian Ruiz','2025-08-28','Es un aeropuerto pequeño…'],
		['P G','2025-08-28','For being a small airport we were amazed…'],
		['TJ Lin','2025-08-26','A world apart from LAX!! Much more pleasant…'],
		['David H','2025-08-25','This is my favorite in the U.S. It’s small…'],
		['Shawn Johnson','2025-08-25','Wonderful small airport. Very easy to get in & out…'],
		['Joshua dawes','2025-08-24','I am a caregiver for a man with Muscular Dystrophy…'],
	];
	$items=array_slice($items,0,$count);
	ob_start(); ?>
	<div class="arw-row-sim">
	<?php foreach($items as $it): ?>
		<div class="arw-card-sim">
			<div class="arw-card-h">
				<div class="arw-avatar">A</div>
				<div>
					<div class="arw-name"><?php echo esc_html($it[0]); ?></div>
					<div class="arw-subtle"><?php echo esc_html($it[1]); ?> · ★★★★★</div>
				</div>
				<div style="margin-left:auto" title="Google">🔵</div>
			</div>
			<div class="arw-text-sim"><?php echo esc_html($it[2]); ?></div>
		</div>
	<?php endforeach; ?>
	</div>
	<?php return ob_get_clean();
}

function awesome_reviews_render_wizard_page(){
	if ( ! current_user_can('manage_options') ) return;

	$step  = isset($_POST['arw_step']) ? (int)$_POST['arw_step'] : ( isset($_GET['step']) ? (int)$_GET['step'] : 1 );
	$step  = max(1,min(5,$step));
	$draft = arw_wiz_get();
	$sources = get_option('awesome_reviews_sources',[]);
	if ( ! is_array($sources) ) $sources=[];

	$notice_html = '';

	/* delete widget inline (no redirects) */
	if ( isset($_GET['arw_delete']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'],'arw_del_'.$_GET['arw_delete']) ) {
		$widgets=get_option('awesome_reviews_widgets',[]);
		if(isset($widgets[$_GET['arw_delete']])){ unset($widgets[$_GET['arw_delete']]); update_option('awesome_reviews_widgets',$widgets); }
		$notice_html = '<div class="notice notice-success"><p>Widget deleted.</p></div>';
	}

	/* POST handlers */
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		// Step 1
		if ( $step===1 ) {
			$ok = isset($_POST['arw_wiz_nonce']) && wp_verify_nonce($_POST['arw_wiz_nonce'],'arw_wiz_step1');
			if ( ! $ok ) { $notice_html = '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>'; }
			else {
			$draft['label']      = sanitize_text_field($_POST['label'] ?? '');
			$draft['source']     = sanitize_text_field($_POST['source'] ?? '');
			// No per-widget API keys; discovery happens from Source (SerpApi)
			arw_wiz_put($draft);
			$step = 2;
			}
		}
		// Step 2
		if ( $step===2 ) {
			$ok = isset($_POST['arw_wiz_nonce']) && wp_verify_nonce($_POST['arw_wiz_nonce'],'arw_wiz_step2');
			if ( ! $ok ) { $notice_html = '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>'; }
			else {
			$preset = sanitize_text_field($_POST['layoutPreset'] ?? 'slider1');
			$allowed = ['slider1','slider2','grid1','list1'];
			if ( ! in_array($preset,$allowed,true) ) $preset='slider1';
			$draft['layoutPreset'] = $preset;
			$draft['layout'] = (substr($preset,0,4)==='grid') ? 'grid' : ((substr($preset,0,4)==='list') ? 'list' : 'slider');
			arw_wiz_put($draft);
			$step = 3;
			}
		}
		// Step 3
		if ( $step===3 ) {
			$ok = isset($_POST['arw_wiz_nonce']) && wp_verify_nonce($_POST['arw_wiz_nonce'],'arw_wiz_step3');
			if ( ! $ok ) { $notice_html = '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>'; }
			else {
			$style = sanitize_text_field($_POST['style'] ?? 'light');
			if ( ! in_array($style,['light','dark'],true) ) $style='light';
			$draft['style']   = $style;
			$draft['tiStyle'] = sanitize_text_field($_POST['tiStyle'] ?? 'style1');
			arw_wiz_put($draft);
			$step = 4;
			}
		}
		// Step 4 → Save widget and go to Step 5
		if ( $step===4 ) {
			$ok = isset($_POST['arw_wiz_nonce']) && wp_verify_nonce($_POST['arw_wiz_nonce'],'arw_wiz_step4');
			if ( ! $ok ) { $notice_html = '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>'; }
			else {
			$bool = fn($k)=>!empty($_POST[$k])?1:0;
			$text = fn($k)=>sanitize_text_field($_POST[$k] ?? '');
			$int  = fn($k,$min,$max,$def)=>max($min,min($max, isset($_POST[$k])?(int)$_POST[$k]:$def ));

			$draft['filterRatings']     = $text('filterRatings');
			$draft['language']          = $text('language');
			$draft['dateFormat']        = $text('dateFormat');
			$draft['nameFormat']        = $text('nameFormat');
			$draft['align']             = $text('align');
			$draft['reviewText']        = $text('reviewText');
			$draft['minRating']         = $int('minRating',1,5,4);
			$draft['maxRating']         = $int('maxRating',1,5,5); // NEW: cap upper bound
			$draft['limit']             = $int('limit',1,100,9);   // allow larger numbers (Google/Yelp may still cap)
			$draft['ttl']               = max(300,(int)($_POST['ttl'] ?? 21600));
			$draft['hideNoText']        = $bool('hideNoText');
			$draft['showMinFilter']     = $bool('showMinFilter');
			$draft['showReply']         = $bool('showReply');
			$draft['showVerifiedIcon']  = $bool('showVerifiedIcon');
			$draft['showNav']           = $bool('showNav');
			$draft['showAvatar']        = $bool('showAvatar');
			$draft['avatarLocal']       = $bool('avatarLocal');
			$draft['showPhotos']        = $bool('showPhotos');
			$draft['hoverAnim']         = $bool('hoverAnim');
			$draft['useSiteFont']       = $bool('useSiteFont');
			$draft['showPlatformLogos'] = $bool('showPlatformLogos');

			$widgets = get_option('awesome_reviews_widgets',[]);
			if ( ! is_array($widgets) ) $widgets=[];
			$id = 'wd_' . substr( wp_hash(microtime(true).wp_rand()),0,7 );

			$widgets[$id] = array_merge([
				'id'           => $id,
				'label'        => $draft['label'] ?? '',
				'mode'         => 'saved',
				'source'       => $draft['source'] ?? '',
				// no per-widget API keys stored here
				'layout'       => $draft['layout'] ?? 'slider',
				'layoutPreset' => $draft['layoutPreset'] ?? 'slider1',
				'style'        => $draft['style'] ?? 'light',
				'tiStyle'      => $draft['tiStyle'] ?? 'style1',
			],[
				'filterRatings'     => $draft['filterRatings'] ?? 'all',
				'language'          => $draft['language'] ?? 'en',
				'dateFormat'        => $draft['dateFormat'] ?? 'Y-m-d',
				'nameFormat'        => $draft['nameFormat'] ?? 'none',
				'align'             => $draft['align'] ?? 'left',
				'reviewText'        => $draft['reviewText'] ?? 'Read more',
				'minRating'         => $draft['minRating'] ?? 4,
				'maxRating'         => $draft['maxRating'] ?? 5,
				'limit'             => $draft['limit'] ?? 9,
				'ttl'               => $draft['ttl'] ?? 21600,
				'hideNoText'        => !empty($draft['hideNoText']),
				'showMinFilter'     => !empty($draft['showMinFilter']),
				'showReply'         => !empty($draft['showReply']),
				'showVerifiedIcon'  => !empty($draft['showVerifiedIcon']),
				'showNav'           => !empty($draft['showNav']),
				'showAvatar'        => !empty($draft['showAvatar']),
				'avatarLocal'       => !empty($draft['avatarLocal']),
				'showPhotos'        => !empty($draft['showPhotos']),
				'hoverAnim'         => !empty($draft['hoverAnim']),
				'useSiteFont'       => !empty($draft['useSiteFont']),
				'showPlatformLogos' => !empty($draft['showPlatformLogos']),
			]);

			update_option('awesome_reviews_widgets',$widgets);
			$draft['last_widget_id']=$id; arw_wiz_put($draft);
			$step = 5;
			}
		}
		// Step 5 → start new
		if ( $step===5 && isset($_POST['arw_wiz_new']) ) {
			$ok = isset($_POST['arw_wiz_nonce']) && wp_verify_nonce($_POST['arw_wiz_nonce'],'arw_wiz_step5');
			if ( ! $ok ) { $notice_html = '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>'; }
			else { arw_wiz_clear(); $step = 1; }
		}
	}

	$val = fn($k,$d='') => isset($draft[$k]) ? $draft[$k] : $d;
	?>
	<div class="wrap">
		<h1>Widget Builder</h1>
		<?php echo $notice_html; ?>
		<?php arw_wiz_step_header($step); ?>

		<div class="arw-card">
		<?php if ($step===1): ?>
			<form method="post">
				<input type="hidden" name="arw_step" value="1" />
				<?php wp_nonce_field('arw_wiz_step1','arw_wiz_nonce'); ?>

				<h2 class="arw-h2">1. Connect Source</h2>
				<p class="arw-help">Pick from your saved <strong>Sources</strong> or a quick pick that uses your global Settings.</p>

				<label class="arw-label">Label (optional)</label>
				<input type="text" name="label" class="arw-input" value="<?php echo esc_attr($val('label')); ?>" placeholder="e.g. Southside Google Widget" />

				<label class="arw-label" style="margin-top:12px">— Select Source —</label>
				<select name="source" class="arw-input" required>
					<option value="">Select…</option>
					<optgroup label="Quick pick (from Settings)">
						<option value="prov:google"   <?php selected($val('source'),'prov:google'); ?>>Google (Settings)</option>
						<option value="prov:yelp"     <?php selected($val('source'),'prov:yelp'); ?>>Yelp (Settings)</option>
						<option value="prov:facebook" <?php selected($val('source'),'prov:facebook'); ?>>Facebook (Settings)</option>
						<option value="prov:all"      <?php selected($val('source'),'prov:all'); ?>>All (aggregate)</option>
					</optgroup>
					<?php if ( ! empty($sources) ) : ?>
					<optgroup label="Saved Sources">
						<?php foreach ($sources as $s):
							$nm=strtoupper($s['provider'] ?? '?'); $lbl=esc_html(($s['label'] ?? 'Untitled')." ($nm)");
							$id=esc_attr($s['id'] ?? ''); $sel=selected($val('source'),$id,false);
							echo "<option value='{$id}' {$sel}>{$lbl}</option>";
						endforeach; ?>
					</optgroup>
					<?php endif; ?>
				</select>

				<p class="description">API keys are managed in <strong>Reviews → Sources</strong>. Select your saved source above; discovery and fetching will happen automatically.</p>

				<p style="margin-top:16px">
					<button type="submit" class="button button-primary button-hero">Continue to Step 2</button>
					<a class="button" href="<?php echo arw_wiz_url(2); ?>">Skip</a>
				</p>
			</form>
		<?php endif; ?>

		<?php if ($step===2): ?>
			<form method="post" id="arw-step2">
				<input type="hidden" name="arw_step" value="2" />
				<?php wp_nonce_field('arw_wiz_step2','arw_wiz_nonce'); ?>
				<input type="hidden" name="layoutPreset" id="arw-layoutPreset" value="<?php echo esc_attr($val('layoutPreset','slider1')); ?>"/>

				<h2 class="arw-h2">2. Select Layout</h2>
				<p class="arw-help">Pick a layout preset. You can style it next.</p>

				<div class="arw-gallery">
					<div class="arw-layout-card <?php echo $val('layoutPreset','slider1')==='slider1'?'is-selected':''; ?>" data-preset="slider1">
						<div class="arw-layout-title">Layout: <strong>Slider I.</strong></div>
						<button type="button" class="button arw-select" data-preset="slider1">Select</button>
						<div class="arw-sim arw-sim--slider"><?php echo arw_fake_reviews_html('light'); ?></div>
					</div>

					<div class="arw-layout-card <?php echo $val('layoutPreset')==='slider2'?'is-selected':''; ?>" data-preset="slider2">
						<div class="arw-layout-title">Layout: <strong>Slider II.</strong> <span class="arw-badge">MOST POPULAR</span></div>
						<button type="button" class="button arw-select" data-preset="slider2">Select</button>
						<div class="arw-sim arw-sim--slider"><?php echo arw_fake_reviews_html('light'); ?></div>
					</div>

					<div class="arw-layout-card <?php echo $val('layoutPreset')==='grid1'?'is-selected':''; ?>" data-preset="grid1">
						<div class="arw-layout-title">Layout: <strong>Grid I.</strong></div>
						<button type="button" class="button arw-select" data-preset="grid1">Select</button>
						<div class="arw-sim arw-sim--grid"><?php echo arw_fake_reviews_html('light',6,true); ?></div>
					</div>

					<div class="arw-layout-card <?php echo $val('layoutPreset')==='list1'?'is-selected':''; ?>" data-preset="list1">
						<div class="arw-layout-title">Layout: <strong>List I.</strong></div>
						<button type="button" class="button arw-select" data-preset="list1">Select</button>
						<div class="arw-sim arw-sim--list"><?php echo arw_fake_reviews_html('light',4); ?></div>
					</div>
				</div>

				<p style="margin-top:16px">
					<a class="button" href="<?php echo arw_wiz_url(1); ?>">Back</a>
					<button type="submit" class="button button-primary button-hero">Continue to Step 3</button>
				</p>
			</form>
		<?php endif; ?>

		<?php if ($step===3): ?>
			<form method="post">
				<input type="hidden" name="arw_step" value="3" />
				<?php wp_nonce_field('arw_wiz_step3','arw_wiz_nonce'); ?>
				<h2 class="arw-h2">3. Select Style</h2>

				<div class="arw-row">
					<label class="arw-radio"><input type="radio" name="style" value="light" <?php checked($val('style','light'),'light'); ?>> Light background</label>
					<label class="arw-radio"><input type="radio" name="style" value="dark"  <?php checked($val('style','light'),'dark');  ?>> Dark background</label>
				</div>

				<div class="arw-row" style="margin-top:8px;max-width:520px">
					<label class="arw-label" style="width:100%">Verified by Trustindex</label>
					<select name="tiStyle" class="arw-input">
						<option value="style1" <?php selected($val('tiStyle','style1'),'style1'); ?>>Style 1</option>
						<option value="style2" <?php selected($val('tiStyle','style1'),'style2'); ?>>Style 2</option>
						<option value="style3" <?php selected($val('tiStyle','style1'),'style3'); ?>>Style 3</option>
					</select>
				</div>

				<p style="margin-top:16px">
					<a class="button" href="<?php echo arw_wiz_url(2); ?>">Back</a>
					<button type="submit" class="button button-primary button-hero">Continue to Step 4</button>
				</p>
			</form>
		<?php endif; ?>

		<?php if ($step===4): ?>
			<form method="post" id="arw-wizard-form">
				<input type="hidden" name="arw_step" value="4" />
				<?php wp_nonce_field('arw_wiz_step4','arw_wiz_nonce'); ?>

				<h2 class="arw-h2">4. Set up widget</h2>

				<div class="arw-setup">
					<div class="left">
						<label class="arw-label">Filter your ratings</label>
						<select name="filterRatings" id="arw-filter" class="arw-input">
							<option value="all"    <?php selected($val('filterRatings','all'),'all'); ?>>Show all</option>
							<option value="4plus"  <?php selected($val('filterRatings','all'),'4plus'); ?>>Show 4★ and above</option>
							<option value="5only"  <?php selected($val('filterRatings','all'),'5only'); ?>>Show only 5★</option>
						</select>

						<label class="arw-label">Select language</label>
						<select name="language" class="arw-input">
							<option value="en" <?php selected($val('language','en'),'en'); ?>>English</option>
							<option value="es" <?php selected($val('language','en'),'es'); ?>>Spanish</option>
							<option value="fr" <?php selected($val('language','en'),'fr'); ?>>French</option>
							<option value="de" <?php selected($val('language','en'),'de'); ?>>German</option>
						</select>

						<label class="arw-label">Select date format</label>
						<input type="text" name="dateFormat" class="arw-input" value="<?php echo esc_attr($val('dateFormat','Y-m-d')); ?>" />

						<label class="arw-label">Select name format</label>
						<select name="nameFormat" class="arw-input">
							<option value="none"          <?php selected($val('nameFormat','none'),'none'); ?>>Do not format</option>
							<option value="initial_last"  <?php selected($val('nameFormat','none'),'initial_last'); ?>>A. Kumar</option>
							<option value="first_only"    <?php selected($val('nameFormat','none'),'first_only'); ?>>First name only</option>
							<option value="initials"      <?php selected($val('nameFormat','none'),'initials'); ?>>A K</option>
						</select>

						<div class="arw-grid2">
							<div>
								<label class="arw-label">Align</label>
								<select name="align" class="arw-input">
									<option value="left"   <?php selected($val('align','left'),'left'); ?>>left</option>
									<option value="center" <?php selected($val('align','left'),'center'); ?>>center</option>
									<option value="right"  <?php selected($val('align','left'),'right'); ?>>right</option>
								</select>
							</div>
							<div>
								<label class="arw-label">Review text</label>
								<input type="text" name="reviewText" class="arw-input" value="<?php echo esc_attr($val('reviewText','Read more')); ?>" />
							</div>
							<div>
								<label class="arw-label">Minimum rating</label>
								<input type="number" name="minRating" id="arw-min" class="arw-input" value="<?php echo esc_attr($val('minRating',4)); ?>" min="1" max="5" />
							</div>
							<div>
								<label class="arw-label">Maximum rating</label>
								<input type="number" name="maxRating" id="arw-max" class="arw-input" value="<?php echo esc_attr($val('maxRating',5)); ?>" min="1" max="5" />
							</div>
							<div>
								<label class="arw-label">Max reviews</label>
								<input type="number" name="limit" class="arw-input" value="<?php echo esc_attr($val('limit',9)); ?>" min="1" max="100" />
							</div>
							<div>
								<label class="arw-label">Cache TTL (seconds)</label>
								<input type="number" name="ttl" class="arw-input" value="<?php echo esc_attr($val('ttl',21600)); ?>" min="300" />
							</div>
						</div>
					</div>

					<div class="right">
						<label class="arw-check"><input type="checkbox" name="hideNoText"        <?php checked(!empty($val('hideNoText'))); ?>> Hide reviews without comments</label>
						<label class="arw-check"><input type="checkbox" name="showMinFilter"     <?php checked(!empty($val('showMinFilter'))); ?>> Show minimum review filter condition</label>
						<label class="arw-check"><input type="checkbox" name="showReply"         <?php checked(!empty($val('showReply'))); ?>> Show review reply</label>
						<label class="arw-check"><input type="checkbox" name="showVerifiedIcon"  <?php checked($val('showVerifiedIcon',1)); ?>> Show verified review icon</label>
						<label class="arw-check"><input type="checkbox" name="showNav"           <?php checked($val('showNav',1)); ?>> Show navigation arrows</label>
						<label class="arw-check"><input type="checkbox" name="showAvatar" id="arw-avatar" <?php checked($val('showAvatar',1)); ?>> Show reviewer's profile picture</label>
						<label class="arw-check arw-sub"><input type="checkbox" name="avatarLocal" id="arw-avatar-local" <?php checked(!empty($val('avatarLocal'))); ?>> Show reviewer's profile picture locally, from a single image (less requests)</label>
						<label class="arw-check"><input type="checkbox" name="showPhotos"        <?php checked(!empty($val('showPhotos'))); ?>> Show photos in reviews</label>
						<label class="arw-check"><input type="checkbox" name="hoverAnim"         <?php checked($val('hoverAnim',1)); ?>> Enable mouseover animation</label>
						<label class="arw-check"><input type="checkbox" name="useSiteFont"       <?php checked(!empty($val('useSiteFont'))); ?>> Use site's font</label>
						<label class="arw-check"><input type="checkbox" name="showPlatformLogos" <?php checked($val('showPlatformLogos',1)); ?>> Show platform logos</label>
					</div>
				</div>

				<p style="margin-top:16px">
					<a class="button" href="<?php echo arw_wiz_url(3); ?>">Back</a>
					<button type="submit" class="button button-primary button-hero">Save widget &amp; continue → Step 5</button>
				</p>
			</form>
		<?php endif; ?>

		<?php if ($step===5):
			$widgets = get_option('awesome_reviews_widgets',[]);
			if ( ! is_array($widgets) ) $widgets=[];
			$wid = isset($_GET['widget']) ? sanitize_text_field($_GET['widget']) : ($draft['last_widget_id'] ?? '');
			$embed = $wid ? 'arw:' . $wid : '';
		?>
			<h2 class="arw-h2">5. Insert code</h2>
			<?php if ($embed): ?>
				<p>Paste this into the block’s <strong>Custom widget id</strong> in the editor:</p>
				<code style="font-size:16px;padding:6px 8px;display:inline-block;background:#f6f7f7;border-radius:6px;"><?php echo esc_html($embed); ?></code>
				&nbsp;<button class="button" data-copy="<?php echo esc_attr($embed); ?>" onclick="navigator.clipboard.writeText(this.dataset.copy); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy',1200);">Copy</button>
				<p class="arw-muted">Or shortcode: <code>[awesome_reviews widget="<?php echo esc_attr($embed); ?>"]</code></p>
			<?php else: ?>
				<p>No widget created yet. Go back to <a href="<?php echo arw_wiz_url(4); ?>">Step 4</a> and save.</p>
			<?php endif; ?>

			<form method="post" style="margin-top:16px">
				<input type="hidden" name="arw_step" value="5" />
				<?php wp_nonce_field('arw_wiz_step5','arw_wiz_nonce'); ?>
				<button name="arw_wiz_new" value="1" class="button">Start a new widget</button>
			</form>

			<h2 class="arw-h2" style="margin-top:24px">Your widgets</h2>
			<table class="widefat striped">
				<thead><tr><th>Label</th><th>Widget ID</th><th>Layout</th><th>Preset</th><th>Style</th><th>Min ★</th><th>Max ★</th><th>Limit</th><th>Source</th><th></th></tr></thead>
				<tbody>
				<?php if (empty($widgets)): ?>
					<tr><td colspan="10">No widgets yet.</td></tr>
				<?php else: foreach($widgets as $w): ?>
					<tr>
						<td><?php echo esc_html($w['label']); ?></td>
						<td><code><?php echo 'arw:' . esc_html($w['id']); ?></code></td>
						<td><?php echo esc_html($w['layout']); ?></td>
						<td><?php echo esc_html($w['layoutPreset'] ?? 'slider1'); ?></td>
						<td><?php echo esc_html($w['style']); ?></td>
						<td><?php echo (int)($w['minRating'] ?? 1); ?></td>
						<td><?php echo (int)($w['maxRating'] ?? 5); ?></td>
						<td><?php echo (int)$w['limit']; ?></td>
						<td><?php echo esc_html($w['source']); ?></td>
						<td><a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg(['arw_delete'=>$w['id']]), 'arw_del_'.$w['id'] ) ); ?>" onclick="return confirm('Delete this widget?');">Delete</a></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
		</div>
	</div>

	<style>
	.arw-steps{display:flex;align-items:center;gap:12px;margin:10px 0 18px}
	.arw-steps .step{display:flex;align-items:center;gap:8px;font-weight:700;color:#111;text-decoration:none}
	.arw-steps .step span{display:grid;place-items:center;width:28px;height:28px;border-radius:999px;background:#16a34a;color:#fff}
	.arw-steps .step.current span{background:#111}
	.arw-steps .caret{color:#9ca3af;font-size:24px;line-height:1}
	.arw-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,.03);padding:18px;margin:0 0 18px;max-width:1140px}
	.arw-h2{margin:8px 0 8px;font-size:18px}
	.arw-help{margin-top:0;color:#555}
	.arw-label{display:block;margin:10px 0 6px;font-weight:600}
	.arw-input{width:100%;max-width:540px}
	.arw-row{display:flex;gap:16px;flex-wrap:wrap}
	.arw-grid2{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:14px;max-width:820px}
	@media(max-width:720px){.arw-grid2{grid-template-columns:1fr}}
	.arw-setup{display:grid;grid-template-columns:1.3fr .9fr;gap:24px;align-items:start}
	@media(max-width:1024px){.arw-setup{grid-template-columns:1fr}}
	.right .arw-check{display:flex;gap:10px;align-items:flex-start;margin:10px 0}
	.right .arw-sub{margin-left:24px}
	.arw-muted{opacity:.75}

	.arw-gallery{display:grid;grid-template-columns:repeat(2,minmax(320px,1fr));gap:18px}
	@media(max-width:1100px){.arw-gallery{grid-template-columns:1fr}}
	.arw-layout-card{position:relative;border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fafafa}
	.arw-layout-card.is-selected{outline:2px solid #2563eb}
	.arw-layout-title{font-weight:700;margin-bottom:8px}
	.arw-badge{display:inline-block;background:#f97316;color:#fff;border-radius:999px;padding:2px 8px;font-size:11px;margin-left:6px}
	.arw-select{position:absolute;top:10px;right:10px}
	.arw-sim{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px}
	.arw-sim--slider .arw-row-sim{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
	.arw-sim--grid   .arw-row-sim{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
	.arw-sim--list   .arw-row-sim{display:grid;grid-template-columns:1fr;gap:12px}
	.arw-card-sim{border:1px solid #e5e7eb;border-radius:12px;padding:12px;min-height:120px}
	.arw-card-h{display:flex;align-items:center;gap:10px;margin-bottom:6px}
	.arw-avatar{width:36px;height:36px;border-radius:999px;background:#ef4444;color:#fff;display:grid;place-items:center;font-weight:700}
	.arw-name{font-weight:700}
	.arw-subtle{font-size:12px;color:#6b7280}
	</style>

	<script>
	// Step2 gallery selection
	(function(){
	  const form=document.getElementById('arw-step2'); if(!form) return;
	  const input=document.getElementById('arw-layoutPreset');
	  document.querySelectorAll('.arw-select').forEach(btn=>{
	    btn.addEventListener('click',()=>{ input.value=btn.dataset.preset; form.submit(); });
	  });
	  document.querySelectorAll('.arw-layout-card').forEach(card=>{
	    card.addEventListener('click',e=>{ if(e.target.closest('button'))return; card.querySelector('.arw-select').click(); });
	  });
	})();
	// Step4 helpers
	(function(){
	  const filter=document.getElementById('arw-filter');
	  const min=document.getElementById('arw-min');
	  const max=document.getElementById('arw-max');
	  const avatar=document.getElementById('arw-avatar');
	  const local=document.getElementById('arw-avatar-local');
	  if(filter&&min){ filter.addEventListener('change',function(){
	    if(this.value==='4plus'){ min.value=4; max.value=5; }
	    if(this.value==='5only'){ min.value=5; max.value=5; }
	    if(this.value==='all'){ if(min.value<1||min.value>5)min.value=1; if(max.value<1||max.value>5)max.value=5; }
	  }); }
	  if(avatar&&local){ function t(){ local.disabled=!avatar.checked; } avatar.addEventListener('change',t); t(); }
	})();
	</script>
	<?php
}
