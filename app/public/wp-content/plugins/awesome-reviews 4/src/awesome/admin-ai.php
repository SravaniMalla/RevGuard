<?php
if ( ! defined('ABSPATH') ) exit;

/* ======================================================================== *
 * AI UTILITIES (OpenAI key storage)
 * ======================================================================== */
function awesome_reviews_ai_get_openai_key(){
	if ( defined('OPENAI_API_KEY') && OPENAI_API_KEY ) return OPENAI_API_KEY;
	return trim( get_option('awesome_reviews_openai_key','') );
}
function awesome_reviews_ai_set_openai_key($key){
	update_option('awesome_reviews_openai_key', trim((string)$key));
}

/* --- tiny HTTP helpers --- */
function awesome_reviews_ai_http_get_json($url,$args=[]){
	$r=wp_remote_get($url,$args);
	if(is_wp_error($r))return null;
	$c=wp_remote_retrieve_response_code($r);
	if($c<200||$c>=300)return null;
	$js=json_decode(wp_remote_retrieve_body($r),true);
	return is_array($js)?$js:null;
}
function awesome_reviews_ai_unify($arr){
	$now=time();
	return [
		'id'       => (string)($arr['id'] ?? md5(json_encode($arr))),
		'provider' => (string)($arr['provider'] ?? 'google'),
		'author'   => (string)($arr['author'] ?? 'Anonymous'),
		'avatar'   => (string)($arr['avatar'] ?? ''),
		'rating'   => max(1,min(5,(int)($arr['rating'] ?? 5))),
		'text'     => (string)($arr['text'] ?? ''),
		'time'     => (int)($arr['time'] ?? $now),
		'url'      => (string)($arr['url'] ?? ''),
		'reply'    => (string)($arr['reply'] ?? ''),
		'email'    => '',
	];
}

/* ======================================================================== *
 * SerpApi helpers (mirror your render.php flow)
 * ======================================================================== */
function awesome_reviews_ai_parse_maps_url($input){
	$out=['title'=>'','lat'=>'','lng'=>''];
	$input=trim((string)$input);
	if($input==='') return $out;
	if(filter_var($input,FILTER_VALIDATE_URL)){
		$parts=wp_parse_url($input);
		$path=$parts['path']??''; $pathParts=array_values(array_filter(explode('/',$path)));
		for($i=0;$i<count($pathParts);$i++){
			if($pathParts[$i]==='place' && isset($pathParts[$i+1])){ $out['title']=urldecode(str_replace('+',' ',$pathParts[$i+1])); break; }
		}
		if(!empty($parts['path'])){
			$atPos=strpos($parts['path'],'@');
			if($atPos!==false){
				$seg=explode('/', substr($parts['path'],$atPos+1))[0] ?? '';
				$coords=explode(',', $seg);
				if(count($coords)>=2){ $out['lat']=$coords[0]; $out['lng']=$coords[1]; }
			}
		}
	}
	return $out;
}
function awesome_reviews_ai_serp_get($params,$serp_key){
	if(!$serp_key) return null;
	$base='https://serpapi.com/search.json';
	$params['api_key']=$serp_key;
	$url=add_query_arg($params,$base);
	return awesome_reviews_ai_http_get_json($url);
}
/** Google reviews via SerpApi (search -> place_id -> reviews) */
function awesome_reviews_ai_fetch_google_serp($place_input,$serp_key,$lang,$limit){
	if(!$serp_key || !$place_input) return [];
	$pid = '';
	if (preg_match('/^[A-Za-z0-9\-_]{20,}$/', trim($place_input))) {
		$pid = trim($place_input);
	} else {
		$meta   = awesome_reviews_ai_parse_maps_url($place_input);
		$params = [
			'engine'=>'google_maps','type'=>'search',
			'q'     => $meta['title'] ?: $place_input,
			'hl'    => $lang ?: 'en'
		];
		if($meta['lat'] && $meta['lng']) $params['ll']='@'.$meta['lat'].','.$meta['lng'].',15z';
		$gm  = awesome_reviews_ai_serp_get($params,$serp_key);
		$pid = $gm['place_results']['place_id'] ?? ($gm['local_results'][0]['place_id'] ?? '');
	}
	if(!$pid) return [];
	$grevs = awesome_reviews_ai_serp_get([
		'engine'=>'google_maps_reviews','place_id'=>$pid,'hl'=>$lang ?: 'en'
	], $serp_key);

	$out=[]; foreach((array)($grevs['reviews'] ?? []) as $r){
		$out[] = awesome_reviews_ai_unify([
			'id'      => ($r['review_id'] ?? md5(json_encode($r))).'_g',
			'provider'=> 'google',
			'author'  => $r['user']['name'] ?? 'Google User',
			'avatar'  => $r['user']['thumbnail'] ?? '',
			'rating'  => isset($r['rating'])?(int)$r['rating']:5,
			'text'    => $r['snippet'] ?? ($r['extracted_snippet']['original'] ?? ''),
			'time'    => !empty($r['iso_date']) ? strtotime($r['iso_date']) : time(),
			'url'     => $r['link'] ?? '',
		]);
	}
	return array_slice($out,0,max(1,(int)$limit));
}
/** Yelp via SerpApi (optional for provider=all) */
function awesome_reviews_ai_fetch_yelp_serp($title_or_input,$address_hint,$serp_key,$limit){
	if(!$serp_key) return [];
	$ys = awesome_reviews_ai_serp_get([
		'engine'=>'yelp','find_desc'=>$title_or_input,'find_loc'=>$address_hint ?: 'United States'
	], $serp_key);
	$place_id='';
	if(!empty($ys['organic_results'][0]['place_ids'][0])) $place_id=$ys['organic_results'][0]['place_ids'][0];
	elseif(!empty($ys['organic_results'][0]['link']) && preg_match('#/biz/([^?]+)#',$ys['organic_results'][0]['link'],$m)) $place_id=$m[1];
	if(!$place_id) return [];
	$yrevs = awesome_reviews_ai_serp_get([
		'engine'=>'yelp_reviews','place_id'=>$place_id,'num'=>min((int)$limit,50)
	], $serp_key);
	$out=[]; foreach((array)($yrevs['reviews'] ?? []) as $r){
		$out[] = awesome_reviews_ai_unify([
			'id'      => md5(json_encode($r)).'_y',
			'provider'=> 'yelp',
			'author'  => $r['user']['name'] ?? 'Yelp User',
			'avatar'  => $r['user']['thumbnail'] ?? '',
			'rating'  => isset($r['rating'])?(int)$r['rating']:5,
			'text'    => $r['comment']['text'] ?? '',
			'time'    => !empty($r['date'])?strtotime($r['date']):time(),
			'url'     => $r['user']['link'] ?? '',
		]);
	}
	return array_slice($out,0,max(1,(int)$limit));
}
/** Facebook (Graph API) */
function awesome_reviews_ai_fb_id_from_input($input){
	$input=trim((string)$input);
	if($input==='')return '';
	if(filter_var($input,FILTER_VALIDATE_URL)){
		$p=wp_parse_url($input);
		if(!empty($p['path'])){ $parts=array_values(array_filter(explode('/',$p['path']))); return $parts[0] ?? ''; }
	}
	return $input;
}
function awesome_reviews_ai_fetch_facebook($page_id,$access_token,$limit){
	if(!$page_id||!$access_token)return [];
	$u=add_query_arg([
		'fields'=>'review_text,rating,created_time,reviewer{name,picture}',
		'limit'=>max(1,(int)$limit),
		'access_token'=>$access_token
	],'https://graph.facebook.com/v19.0/'.rawurlencode($page_id).'/ratings');
	$js=awesome_reviews_ai_http_get_json($u); if(!$js||empty($js['data']))return [];
	$out=[]; foreach($js['data'] as $r){
		$out[]=awesome_reviews_ai_unify([
			'id'=>md5(json_encode($r)).'_f','provider'=>'facebook',
			'author'=>$r['reviewer']['name'] ?? 'Facebook User',
			'avatar'=>$r['reviewer']['picture']['data']['url'] ?? '',
			'rating'=>$r['rating'] ?? 5,
			'text'=>$r['review_text'] ?? '',
			'time'=>!empty($r['created_time'])?strtotime($r['created_time']):time(),
			'url'=>'https://facebook.com/'.rawurlencode($page_id).'/reviews',
		]);
	}
	return array_slice($out,0,max(1,(int)$limit));
}

/* ======================================================================== *
 * MAIN LOADER (uses saved Sources; matches widget behaviour)
 * ======================================================================== */
function awesome_reviews_ai_load_reviews_for_widget($widget_id, $force_limit = 0){
	$settings = function_exists('awesome_reviews_get_settings') ? awesome_reviews_get_settings() : [];
	$sources  = get_option('awesome_reviews_sources', []);
	if (!is_array($sources)) $sources = [];

	$limit = $force_limit ?: 200;
	$lang  = 'en';

	$all = [];

	foreach ($sources as $src) {
		$provider = strtolower($src['provider'] ?? 'google');
		$input    = trim((string)($src['input'] ?? ''));          // Google Maps URL (or place_id)
		$serp     = $src['serp_api_key'] ?? ($settings['serpapi_key'] ?? '');

		if ($provider === 'google') {
			$all = array_merge($all, awesome_reviews_ai_fetch_google_serp($input,$serp,$lang,$limit));
		}
		elseif ($provider === 'yelp') {
			$meta   = awesome_reviews_ai_parse_maps_url($input);
			$gsearch= awesome_reviews_ai_serp_get([
				'engine'=>'google_maps','type'=>'search','q'=>$meta['title'] ?: $input,'hl'=>$lang
			], $serp);
			$addr = $gsearch['place_results']['address'] ?? '';
			$all = array_merge($all, awesome_reviews_ai_fetch_yelp_serp($meta['title'] ?: $input,$addr,$serp,$limit));
		}
		elseif ($provider === 'facebook') {
			$token = $settings['facebook_access_token'] ?? '';
			$page  = awesome_reviews_ai_fb_id_from_input($settings['facebook_page_id'] ?? '');
			$all   = array_merge($all, awesome_reviews_ai_fetch_facebook($page,$token,$limit));
		}
		elseif ($provider === 'all') {
			$g = awesome_reviews_ai_fetch_google_serp($input,$serp,$lang,$limit);
			$all = array_merge($all, $g);

			$meta   = awesome_reviews_ai_parse_maps_url($input);
			$gsearch= awesome_reviews_ai_serp_get([
				'engine'=>'google_maps','type'=>'search','q'=>$meta['title'] ?: $input,'hl'=>$lang
			], $serp);
			$addr = $gsearch['place_results']['address'] ?? '';
			$y = awesome_reviews_ai_fetch_yelp_serp($meta['title'] ?: $input,$addr,$serp,$limit);
			$all = array_merge($all, $y);

			if (!empty($settings['facebook_access_token']) && !empty($settings['facebook_page_id'])) {
				$all = array_merge($all, awesome_reviews_ai_fetch_facebook($settings['facebook_page_id'],$settings['facebook_access_token'],$limit));
			}
		}
	}

	// Remove those we already emailed
	$emailed = get_option('awesome_reviews_ai_replied', []);
	if (!is_array($emailed)) $emailed = [];
	$all = array_values(array_filter($all, function($r) use ($emailed){
		return !isset($emailed[$r['id']]);
	}));

	// Filter + sort
	$all = array_values(array_filter($all, function($r){ return !empty($r['text']); }));
	usort($all, fn($a,$b)=> (int)$b['time'] <=> (int)$a['time']);

	return array_slice($all,0,$limit);
}

/* ======================================================================== *
 * OPENAI DRAFT GENERATOR
 * ======================================================================== */
function awesome_reviews_ai_generate_reply($review, $business_name=''){
	$key = awesome_reviews_ai_get_openai_key();
	$business = $business_name ?: wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	$rating = (int)($review['rating'] ?? 0);
	$text   = trim((string)($review['text'] ?? ''));
	$author = trim((string)($review['author'] ?? ''));

	if ($key) {
		$req = [
			'model' => 'gpt-4o-mini',
			'messages' => [
				['role'=>'system','content'=>'You write sincere, concise (2–3 sentences) owner replies to online reviews. Be warm, specific, and never invent facts.'],
				['role'=>'user','content'=> "Business: {$business}\nRating: {$rating}\nReviewer: {$author}\nReview:\n{$text}\n\nWrite a short, friendly reply (no emojis)."]
			],
			'temperature' => 0.4,
			'max_tokens'  => 160,
		];
		$resp = wp_remote_post('https://api.openai.com/v1/chat/completions',[
			'headers'=>[
				'Content-Type'=>'application/json',
				'Authorization'=>'Bearer '.$key,
			],
			'body'=> wp_json_encode($req),
			'timeout'=> 25,
		]);
		if ( ! is_wp_error($resp) ) {
			$code = wp_remote_retrieve_response_code($resp);
			$body = json_decode(wp_remote_retrieve_body($resp), true);
			if ($code>=200 && $code<300 && !empty($body['choices'][0]['message']['content']) ) {
				return trim($body['choices'][0]['message']['content']);
			}
		}
	}

	// Fallback template
	$name = $author ?: 'there';
	if ($rating >= 4) {
		return "Thank you for the {$rating}★ review, {$name}! We're thrilled you had a good experience at {$business}. We appreciate your support and hope to see you again.";
	}
	return "We’re sorry to hear about your experience, {$name}. We take feedback seriously and want to make this right—please reach us at ".get_option('admin_email')." so we can help.";
}

/* ======================================================================== *
 * ACTION HANDLERS: save key, generate draft, save draft, send email
 * ======================================================================== */
add_action('admin_init', function(){
	if ( empty($_POST['arw_ai_action']) || ! current_user_can('manage_options') ) return;
	$act = sanitize_text_field( wp_unslash($_POST['arw_ai_action']) );

	if ( $act === 'save_key' ) {
		check_admin_referer('arw_ai_key');
		awesome_reviews_ai_set_openai_key( wp_unslash($_POST['openai_key'] ?? '') );
		set_transient('awesome_reviews_ai_notice',['type'=>'success','text'=>'OpenAI key saved.'],60);
		wp_safe_redirect( admin_url('admin.php?page=awesome-reviews-ai') );
		exit;
	}

	if ( $act === 'generate' ) {
		check_admin_referer('arw_ai_generate');
		$rid    = sanitize_text_field( wp_unslash($_POST['review_id'] ?? '') );
		$review = [
			'id'     => $rid,
			'rating' => (int)($_POST['rating'] ?? 0),
			'author' => sanitize_text_field( wp_unslash($_POST['author'] ?? '') ),
			'text'   => trim( (string) wp_unslash($_POST['text'] ?? '') ),
		];
		$reply = awesome_reviews_ai_generate_reply($review);

		$drafts = get_option('awesome_reviews_ai_drafts', []);
		if ( !is_array($drafts) ) $drafts=[];
		$drafts[$rid] = $reply;
		update_option('awesome_reviews_ai_drafts', $drafts);

		set_transient('awesome_reviews_ai_notice',['type'=>'success','text'=>'Draft generated.'],60);
		wp_safe_redirect( admin_url('admin.php?page=awesome-reviews-ai') );
		exit;
	}

	if ( $act === 'save_draft' ) {
		check_admin_referer('arw_ai_save');
		$rid = sanitize_text_field( wp_unslash($_POST['review_id'] ?? '') );
		$txt = (string) wp_unslash($_POST['reply_text'] ?? '');

		$drafts = get_option('awesome_reviews_ai_drafts', []);
		if ( !is_array($drafts) ) $drafts=[];
		$drafts[$rid] = $txt;
		update_option('awesome_reviews_ai_drafts', $drafts);

		set_transient('awesome_reviews_ai_notice',['type'=>'success','text'=>'Draft saved.'],60);
		wp_safe_redirect( admin_url('admin.php?page=awesome-reviews-ai') );
		exit;
	}
});

/* Email sender (saves a snapshot for the "Already Responded" section) */
add_action('admin_post_arw_send_reply', 'awesome_reviews_ai_handle_send_email');
function awesome_reviews_ai_handle_send_email(){
	if ( ! current_user_can('manage_options') ) wp_die('Not allowed');
	check_admin_referer('arw_send_reply');

	$rid     = sanitize_text_field( wp_unslash($_POST['arw_review_id'] ?? '') );
	$to      = sanitize_email( wp_unslash($_POST['arw_email_to'] ?? '') );
	$subject = sanitize_text_field( wp_unslash($_POST['arw_email_subject'] ?? '') );
	$message = (string) wp_unslash($_POST['arw_email_message'] ?? '');

	// Snapshot fields
	$r_author   = sanitize_text_field( wp_unslash($_POST['arw_review_author'] ?? '') );
	$r_rating   = (int)($_POST['arw_review_rating'] ?? 0);
	$r_text     = (string) wp_unslash($_POST['arw_review_text'] ?? '');
	$r_provider = sanitize_text_field( wp_unslash($_POST['arw_review_provider'] ?? '') );
	$r_url      = esc_url_raw( wp_unslash($_POST['arw_review_url'] ?? '') );

	if ( empty($rid) || empty($to) || empty($subject) || empty($message) ) {
		set_transient('awesome_reviews_ai_notice',['type'=>'error','text'=>'Missing required email fields.'],60);
		wp_safe_redirect( admin_url('admin.php?page=awesome-reviews-ai') );
		exit;
	}

	$from_email = get_option('admin_email');
	$from_name  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	$headers = [
		'From: '.$from_name.' <'.$from_email.'>',
		'Reply-To: '.$from_name.' <'.$from_email.'>',
		'Content-Type: text/plain; charset=UTF-8'
	];

	$ok = wp_mail($to, $subject, $message, $headers);

	if ($ok){
		// 1) mark as replied (to hide from queue)
		$map = get_option('awesome_reviews_ai_replied', []);
		if (!is_array($map)) $map=[];
		$map[$rid] = time();
		update_option('awesome_reviews_ai_replied', $map);

		// 2) append to responded items log (snapshot)
		$log = get_option('awesome_reviews_ai_replied_items', []);
		if (!is_array($log)) $log=[];
		$log[] = [
			'id'        => $rid,
			'provider'  => $r_provider ?: 'google',
			'author'    => $r_author ?: 'Anonymous',
			'rating'    => max(1,min(5,(int)$r_rating)),
			'text'      => $r_text,
			'url'       => $r_url,
			'to'        => $to,
			'subject'   => $subject,
			'message'   => $message,
			'sent_at'   => time(),
			'resend_count' => 0,
		];
		if (count($log) > 500) { $log = array_slice($log, -500); }
		update_option('awesome_reviews_ai_replied_items', $log);

		set_transient('awesome_reviews_ai_notice',['type'=>'success','text'=>'Email sent and review marked as replied.'],60);
	} else {
		set_transient('awesome_reviews_ai_notice',['type'=>'error','text'=>'Email failed to send. Check your mail setup (SMTP).'],60);
	}
	wp_safe_redirect( admin_url('admin.php?page=awesome-reviews-ai') );
	exit;
}

/* ======================================================================== *
 * RESEND + REGENERATE handlers
 * ======================================================================== */
add_action('admin_post_arw_resend_reply', 'awesome_reviews_ai_handle_resend_email');
function awesome_reviews_ai_handle_resend_email(){
	if ( ! current_user_can('manage_options') ) wp_die('Not allowed');
	check_admin_referer('arw_resend_reply');

	$rid     = sanitize_text_field( wp_unslash($_POST['arw_review_id'] ?? '') );
	$to      = sanitize_email( wp_unslash($_POST['arw_email_to'] ?? '') );
	$subject = sanitize_text_field( wp_unslash($_POST['arw_email_subject'] ?? '') );
	$message = (string) wp_unslash($_POST['arw_email_message'] ?? '');

	if ( empty($rid) || empty($to) || empty($subject) || empty($message) ) {
		set_transient('awesome_reviews_ai_notice',['type'=>'error','text'=>'Missing fields for resend.'],60);
		wp_safe_redirect( admin_url('admin.php?page=awesome-reviews-ai') );
		exit;
	}

	$from_email = get_option('admin_email');
	$from_name  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	$headers = [
		'From: '.$from_name.' <'.$from_email.'>',
		'Reply-To: '.$from_name.' <'.$from_email.'>',
		'Content-Type: text/plain; charset=UTF-8'
	];

	$ok = wp_mail($to, $subject, $message, $headers);

	if ($ok){
		$log = get_option('awesome_reviews_ai_replied_items', []);
		if (!is_array($log)) $log=[];
		foreach($log as &$item){
			if (($item['id'] ?? '') === $rid){
				$item['resent_at']     = time();
				$item['resent_to']     = $to;
				$item['resent_subject']= $subject;
				$item['resent_message']= $message;
				$item['resend_count']  = (int)($item['resend_count'] ?? 0) + 1;
			}
		}
		update_option('awesome_reviews_ai_replied_items', $log);
		set_transient('awesome_reviews_ai_notice',['type'=>'success','text'=>'Email resent successfully.'],60);
	} else {
		set_transient('awesome_reviews_ai_notice',['type'=>'error','text'=>'Resend failed.'],60);
	}

	wp_safe_redirect( admin_url('admin.php?page=awesome-reviews-ai') );
	exit;
}

add_action('admin_post_arw_regen_reply', 'awesome_reviews_ai_handle_regen_reply');
function awesome_reviews_ai_handle_regen_reply(){
	if ( ! current_user_can('manage_options') ) wp_die('Not allowed');
	check_admin_referer('arw_regen_reply');

	$rid   = sanitize_text_field( wp_unslash($_POST['arw_review_id'] ?? '') );
	$text  = (string) wp_unslash($_POST['arw_review_text'] ?? '');
	$rating= (int)($_POST['arw_review_rating'] ?? 0);
	$author= sanitize_text_field( wp_unslash($_POST['arw_review_author'] ?? '') );

	$review = [
		'id'     => $rid,
		'text'   => $text,
		'rating' => $rating,
		'author' => $author,
	];
	$new_reply = awesome_reviews_ai_generate_reply($review);

	// store temp so UI can prefill resend form
	set_transient('awesome_reviews_ai_regen',[
		'rid'   => $rid,
		'reply' => $new_reply,
	], 120);

	wp_safe_redirect( admin_url('admin.php?page=awesome-reviews-ai') );
	exit;
}

/* ======================================================================== *
 * PAGE RENDER
 * ======================================================================== */
function awesome_reviews_render_ai_responses_page(){
	if ( ! current_user_can('manage_options') ) return;

	$notice = get_transient('awesome_reviews_ai_notice');
	if ($notice){ delete_transient('awesome_reviews_ai_notice'); }

	$force_limit = isset($_GET['limit']) ? max(1,min(500,(int)$_GET['limit'])) : 200;
	$openai_key  = awesome_reviews_ai_get_openai_key();

	echo '<div class="wrap"><h1>AI Responses</h1>';

	if ($notice){
		$cls = $notice['type']==='error' ? 'notice-error' : 'notice-success';
		echo '<div class="notice '.$cls.'"><p>'.esc_html($notice['text']).'</p></div>';
	}

	echo '<div class="arw-card" style="max-width:720px">';
	echo '<h2>API Credentials</h2>';
	echo '<form method="post">';
	wp_nonce_field('arw_ai_key');
	echo '<input type="hidden" name="arw_ai_action" value="save_key">';
	echo '<p><label>OpenAI API Key<br><input type="password" name="openai_key" class="regular-text" value="'.esc_attr($openai_key).'" placeholder="sk-..."></label></p>';
	echo '<p class="description">Only used to draft replies. Leave empty to use fallback templates.</p>';
	echo '<p><button class="button button-primary">Save Key</button></p>';
	echo '</form></div>';

	echo '<div class="arw-card" style="max-width:980px">';
	echo '<h2>Load reviews</h2>';
	echo '<form method="get">';
	echo '<input type="hidden" name="page" value="awesome-reviews-ai">';
	echo '<label>Max to load: <input type="number" name="limit" min="1" max="500" value="'.(int)$force_limit.'" style="width:90px"></label> ';
	echo '<button class="button button-primary">Load</button>';
	echo '</form>';

	$reviews = awesome_reviews_ai_load_reviews_for_widget('all', $force_limit);

	if (empty($reviews)){
		echo '<p class="description">No new reviews found. Reviews you already emailed are hidden below in <strong>Already Responded</strong>.</p>';
		echo '</div>';
	} else {
		$lowest  = array_values(array_filter($reviews, fn($r) => (int)$r['rating'] <= 3));
		$highest = array_values(array_filter($reviews, fn($r) => (int)$r['rating'] >= 5));

		$drafts = get_option('awesome_reviews_ai_drafts', []);
		if ( !is_array($drafts) ) $drafts=[];

		$render_item = function($r) use ($drafts){
			$rid   = $r['id'];
			$reply = $drafts[$rid] ?? '';

			echo '<div class="arw-ai-item">';
			echo '<div class="arw-ai-left">';
			echo '<div class="arw-ai-top">'.str_repeat('★',(int)$r['rating']).'<span class="arw-ai-dim">'.str_repeat('★',5-(int)$r['rating']).'</span> · '.esc_html(ucfirst($r['provider'])).'</div>';
			echo '<div class="arw-ai-author"><strong>'.esc_html($r['author']).'</strong>';
			if (!empty($r['url'])) echo ' · <a href="'.esc_url($r['url']).'" target="_blank" rel="nofollow noopener">View</a>';
			echo '</div>';
			echo '<div class="arw-ai-text">'.esc_html($r['text']).'</div>';
			echo '</div>';

			echo '<div class="arw-ai-right">';
			// Generate via OpenAI
			echo '<form method="post" class="arw-inline">';
			wp_nonce_field('arw_ai_generate');
			echo '<input type="hidden" name="arw_ai_action" value="generate">';
			echo '<input type="hidden" name="review_id" value="'.esc_attr($rid).'">';
			echo '<input type="hidden" name="rating" value="'.(int)$r['rating'].'">';
			echo '<input type="hidden" name="author" value="'.esc_attr($r['author']).'">';
			echo '<input type="hidden" name="text" value="'.esc_attr($r['text']).'">';
			echo '<button class="button">Generate</button>';
			echo '</form>';

			// Save draft
			echo '<form method="post">';
			wp_nonce_field('arw_ai_save');
			echo '<input type="hidden" name="arw_ai_action" value="save_draft">';
			echo '<input type="hidden" name="review_id" value="'.esc_attr($rid).'">';
			echo '<textarea name="reply_text" rows="4" class="large-text" placeholder="Reply draft...">'.esc_textarea($reply).'</textarea>';
			echo '<p><button class="button">Save draft</button></p>';
			echo '</form>';

			// Approve & Send email — add snapshot fields so we can show it later
			echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'">';
			echo '<input type="hidden" name="action" value="arw_send_reply">';
			wp_nonce_field('arw_send_reply');
			echo '<input type="hidden" name="arw_review_id" value="'.esc_attr($rid).'">';
			echo '<input type="hidden" name="arw_review_author" value="'.esc_attr($r['author']).'">';
			echo '<input type="hidden" name="arw_review_rating" value="'.(int)$r['rating'].'">';
			echo '<input type="hidden" name="arw_review_text" value="'.esc_attr($r['text']).'">';
			echo '<input type="hidden" name="arw_review_provider" value="'.esc_attr($r['provider']).'">';
			echo '<input type="hidden" name="arw_review_url" value="'.esc_url($r['url']).'">';
			echo '<p><input type="email" name="arw_email_to" class="regular-text" placeholder="Recipient email (required)" required></p>';
			echo '<p><input type="text" name="arw_email_subject" class="regular-text" value="'.esc_attr('Reply to your review at '.get_bloginfo('name')).'"></p>';
			echo '<p><textarea name="arw_email_message" rows="4" class="large-text" placeholder="Email body">'.esc_textarea( $reply ?: 'Thank you for your review!' ).'</textarea></p>';
			echo '<p><button class="button button-primary">Approve & Send Email</button></p>';
			echo '</form>';

			echo '</div>'; // right
			echo '</div>'; // item
		};

		echo '<div class="notice notice-info"><p>Loaded '.count($reviews).' new reviews (excluding ones you already emailed).</p></div>';

		echo '<div class="arw-card"><h2>Lowest Rated (1–3★)</h2><div class="arw-ai-list">';
		foreach($lowest as $r){ $render_item($r); }
		echo '</div></div>';

		echo '<div class="arw-card"><h2>Highest Rated (5★)</h2><div class="arw-ai-list">';
		foreach($highest as $r){ $render_item($r); }
		echo '</div></div>';

		echo '</div>'; // end "Load reviews" card
	}

	/* ---------------- Already Responded log ---------------- */
	$log = get_option('awesome_reviews_ai_replied_items', []);
	if (!is_array($log)) $log=[];
	// newest first
	usort($log, fn($a,$b)=> (int)($b['sent_at'] ?? 0) <=> (int)($a['sent_at'] ?? 0));

	// transient for regenerated drafts
	$regen = get_transient('awesome_reviews_ai_regen');

	echo '<div class="arw-card" style="max-width:1200px">';
	echo '<h2>Already Responded</h2>';

	if (empty($log)){
		echo '<p class="description">You haven’t sent any email replies yet. Once you send, they’ll show up here.</p>';
		echo '</div></div>';
		return;
	}

	echo '<div class="arw-ai-list">';
	foreach($log as $item){
		$rid  = (string)($item['id'] ?? '');
		$sent = !empty($item['sent_at']) ? date_i18n( get_option('date_format').' '.get_option('time_format'), (int)$item['sent_at'] ) : '';
		$resent_note = '';
		if (!empty($item['resent_at'])){
			$resent_note = ' · Resent '. (int)($item['resend_count'] ?? 1) .'× at '. date_i18n(get_option('date_format').' '.get_option('time_format'), (int)$item['resent_at']);
		}

		// Prefill message: if we just regenerated for this rid, use that; else last resent message or original
		$prefill = ($regen && ($regen['rid'] ?? '') === $rid)
			? ($regen['reply'] ?? '')
			: ( ($item['resent_message'] ?? '') !== '' ? $item['resent_message'] : ($item['message'] ?? '') );

		if ($regen && ($regen['rid'] ?? '') === $rid){
			// consume once so it doesn’t affect other rows
			delete_transient('awesome_reviews_ai_regen');
			$regen = null;
		}

		echo '<div class="arw-ai-item">';
		echo '<div class="arw-ai-left">';
		echo '<div class="arw-ai-top">'.str_repeat('★',(int)($item['rating'] ?? 0)).'<span class="arw-ai-dim">'.str_repeat('★',5-(int)($item['rating'] ?? 0)).'</span> · '.esc_html(ucfirst($item['provider'] ?? 'google')).'</div>';
		echo '<div class="arw-ai-author"><strong>'.esc_html($item['author'] ?? 'Anonymous').'</strong>';
		if (!empty($item['url'])) echo ' · <a href="'.esc_url($item['url']).'" target="_blank" rel="nofollow noopener">View</a>';
		echo '</div>';
		echo '<div class="arw-ai-text">'.esc_html($item['text'] ?? '').'</div>';
		echo '<div class="arw-ai-dim">Sent '.$sent.' to '.esc_html($item['to'] ?? '').' &middot; Subject: '.esc_html($item['subject'] ?? '').$resent_note.'</div>';
		echo '</div>';

		echo '<div class="arw-ai-right">';

		// Show last sent body (read-only)
		echo '<textarea rows="4" class="large-text" readonly>'.esc_textarea($item['message'] ?? '').'</textarea>';

		// Resend form
		echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'" style="margin-top:8px">';
		echo '<input type="hidden" name="action" value="arw_resend_reply">';
		wp_nonce_field('arw_resend_reply');
		echo '<input type="hidden" name="arw_review_id" value="'.esc_attr($rid).'">';
		echo '<p><input type="email" name="arw_email_to" class="regular-text" value="'.esc_attr($item['resent_to'] ?? $item['to'] ?? '').'" required></p>';
		echo '<p><input type="text" name="arw_email_subject" class="regular-text" value="'.esc_attr($item['resent_subject'] ?? $item['subject'] ?? '').'"></p>';
		echo '<p><textarea name="arw_email_message" rows="4" class="large-text">'.esc_textarea($prefill).'</textarea></p>';
		echo '<p><button class="button button-primary">Resend Email</button></p>';
		echo '</form>';

		// Regenerate with AI (prefills resend form on refresh)
		echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'" style="margin-top:4px">';
		echo '<input type="hidden" name="action" value="arw_regen_reply">';
		wp_nonce_field('arw_regen_reply');
		echo '<input type="hidden" name="arw_review_id" value="'.esc_attr($rid).'">';
		echo '<input type="hidden" name="arw_review_text" value="'.esc_attr($item['text'] ?? '').'">';
		echo '<input type="hidden" name="arw_review_rating" value="'.(int)($item['rating'] ?? 0).'">';
		echo '<input type="hidden" name="arw_review_author" value="'.esc_attr($item['author'] ?? '').'">';
		echo '<button class="button">Regenerate with AI</button>';
		echo '</form>';

		echo '</div>'; // right
		echo '</div>'; // item
	}
	echo '</div>'; // list
	echo '</div>'; // card

	echo '</div>'; // wrap

	// Styles
	?>
	<style>
	.arw-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,.03);padding:18px;margin:0 0 18px}
	.arw-ai-list{display:grid;gap:14px;max-width:1200px}
	.arw-ai-item{display:grid;grid-template-columns:1.2fr .8fr;gap:16px;border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fafafa}
	@media(max-width:960px){.arw-ai-item{grid-template-columns:1fr}}
	.arw-ai-top{font-size:13px;margin-bottom:6px}
	.arw-ai-dim{color:#9ca3af}
	.arw-ai-author{margin-bottom:6px}
	.arw-ai-text{white-space:pre-wrap}
	.arw-ai-right form{margin:0 0 8px}
	.arw-inline{display:inline-block;margin-right:8px}
	</style>
	<?php
}
