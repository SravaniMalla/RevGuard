<?php
if ( ! defined('ABSPATH') ) exit;

return function( $attributes, $content ) {

	$settings = function_exists('awesome_reviews_get_settings') ? awesome_reviews_get_settings() : [
		'google_api_key'         => '',
		'google_place_id'        => '',
		'yelp_api_key'           => '',
		'yelp_business_id'       => '',
		'facebook_access_token'  => '',
		'facebook_page_id'       => '',
		'cache_ttl'              => 21600,
		'max_reviews_default'    => 9,
	];

	/* ---------------- Widget config ---------------- */
	$widget = [];
	if ( ! empty($attributes['customWidgetId']) ) {
		$id = (string) $attributes['customWidgetId'];
		if ( strpos($id,'arw:')===0 ) $id = substr($id,4);
		$all = get_option('awesome_reviews_widgets',[]);
		if ( is_array($all) && isset($all[$id]) ) $widget = $all[$id];
	}

	$w = wp_parse_args($widget, [
		'label'             => '',
		'source'            => '',
		'mode'              => 'saved',
		'google_api'        => '',
		'layout'            => 'slider',
		'layoutPreset'      => 'slider1',
		'style'             => 'light',
		'tiStyle'           => 'style1',
		'minRating'         => 1,
		'maxRating'         => 5,
		'limit'             => max(1,(int)($settings['max_reviews_default'] ?? 9)),
		'ttl'               => max(300,(int)($settings['cache_ttl'] ?? 21600)),
		'filterRatings'     => 'all',
		'language'          => 'en',
		'dateFormat'        => 'Y-m-d',
		'nameFormat'        => 'none',
		'align'             => 'left',
		'reviewText'        => 'Read more',
		'hideNoText'        => 1,
		'showMinFilter'     => 0,
		'showReply'         => 0,
		'showVerifiedIcon'  => 1,
		'showNav'           => 1,
		'showAvatar'        => 1,
		'avatarLocal'       => 0,
		'showPhotos'        => 0,
		'hoverAnim'         => 1,
		'useSiteFont'       => 0,
		'showPlatformLogos' => 1,
	]);

	/* Allow block attributes to override */
	if ( isset($attributes['count']) && is_numeric($attributes['count']) ) {
		$w['limit'] = max(1,(int)$attributes['count']);
	}
	if ( isset($attributes['minRating']) && is_numeric($attributes['minRating']) ) {
		$w['minRating'] = max(1,min(5,(int)$attributes['minRating']));
	}
	$w['layout'] = 'slider';

	/* --------------- Sources --------------- */
	$sources = get_option('awesome_reviews_sources',[]);
	if ( ! is_array($sources) ) $sources=[];
	$source = null;

	if ( ! empty($w['source']) ) {
		foreach ($sources as $row) {
			if ( isset($row['id']) && $row['id']===$w['source'] ) { $source=$row; break; }
		}
	}

	// allow virtual quick-picks: prov:google|yelp|facebook|all
	if ( ! $source && ! empty($w['source']) && preg_match('/^prov:(google|yelp|facebook|all)$/',$w['source'],$m) ) {
		$p=$m[1];
		$source=[
			'id'       => $w['source'],
			'provider' => $p,
			'label'    => ucfirst($p).' (Settings)',
			'input'    => '',
			'api_key'  => '',
		];
	}

	// If Google quick-pick but no global place id, fallback to first saved Google source
	if ( $source && strtolower($source['provider'] ?? '')==='google' ) {
		$hasGlobal = !empty($settings['google_place_id']);
		$srcInput  = trim((string)($source['input'] ?? ''));
		if ( !$hasGlobal && $srcInput === '' ) {
			foreach ($sources as $row) {
				if ( strtolower($row['provider'] ?? '') === 'google' && !empty($row['input']) ) {
					$source = $row; break;
				}
			}
		}
	}

	// Direct attribute Google place override
	$__place_id = isset($attributes['__place_id']) ? trim((string)$attributes['__place_id']) : '';
	if ( $__place_id !== '' ) {
		if ( ! $source ) {
			$source = [ 'id' => 'prov:google', 'provider' => 'google', 'label' => 'Google (Direct)', 'input' => $__place_id, 'api_key' => '' ];
		} elseif ( strtolower($source['provider'] ?? '') === 'google' ) {
			$source['input'] = $__place_id;
		}
	}

	// editor preview when unconfigured
	if ( is_admin() && function_exists('get_current_screen') ) {
		$screen=get_current_screen();
		if ( $screen && $screen->is_block_editor() && empty($source) && empty($widget) ) {
			return '<div class="arw arw--preview"><h3>Business Reviews (preview)</h3>'.arw_fake_cards_html(3,$w['style']).'</div>';
		}
	}

	/* --------------- Fetchers & utils --------------- */
	$http_get_json = function($url,$args=[]){
		$r=wp_remote_get($url,$args);
		if(is_wp_error($r))return null;
		$c=wp_remote_retrieve_response_code($r);
		if($c<200||$c>=300)return null;
		$js=json_decode(wp_remote_retrieve_body($r),true);
		return is_array($js)?$js:null;
	};

	// Parse bits from a Google Maps URL (title and @lat,lng if present)
	$parse_maps_url = function($input){
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
	};

	// SerpApi request helper
	$serp_get = function($params,$serp_key) use($http_get_json){
		if(!$serp_key) return null;
		$base='https://serpapi.com/search.json';
		$params['api_key']=$serp_key;
		$url=add_query_arg($params,$base);
		return $http_get_json($url);
	};

	$unify = function($arr){
		$now=time();
		return [
			'id'       => (string)($arr['id'] ?? md5(json_encode($arr))),
			'provider' => (string)($arr['provider'] ?? 'google'),
			'author'   => (string)($arr['author'] ?? 'Anonymous'),
			'avatar'   => (string)($arr['avatar'] ?? ''),
			'rating'   => max(1,min(5,(int)($arr['rating'] ?? 5))),
			'text'     => (string)($arr['text'] ?? ''),
			'photos'   => is_array($arr['photos'] ?? null) ? $arr['photos'] : [],
			'time'     => (int)($arr['time'] ?? $now),
			'url'      => (string)($arr['url'] ?? ''),
			'reply'    => (string)($arr['reply'] ?? ''),
		];
	};

	$google_resolve_place_id = function($input,$api_key) use ($http_get_json){
		$input=trim((string)$input);
		if($input==='')return '';
		if(preg_match('/^[A-Za-z0-9\-\_]{20,}$/',$input))return $input;
		if(filter_var($input,FILTER_VALIDATE_URL)){
			$parts=wp_parse_url($input); $qs=[];
			if(!empty($parts['query'])) parse_str($parts['query'],$qs);
			if(!empty($qs['place_id'])) return $qs['place_id'];
		}
		$u=add_query_arg(['input'=>rawurlencode($input),'inputtype'=>'textquery','fields'=>'place_id','key'=>$api_key],'https://maps.googleapis.com/maps/api/place/findplacefromtext/json');
		$js=$http_get_json($u);
		return $js['candidates'][0]['place_id'] ?? '';
	};

	$fetch_google=function($place_id,$api_key,$lang,$limit) use($http_get_json,$unify){
		if(!$place_id||!$api_key)return [];
		$u=add_query_arg(['place_id'=>$place_id,'fields'=>'name,url,rating,user_ratings_total,reviews','language'=>$lang?:'en','key'=>$api_key],'https://maps.googleapis.com/maps/api/place/details/json');
		$js=$http_get_json($u); if(!$js||empty($js['result']))return [];
		$out=[]; foreach((array)($js['result']['reviews'] ?? []) as $r){
			$out[]=$unify([
				'id'=>($r['time'] ?? '').'_g','provider'=>'google',
				'author'=>$r['author_name'] ?? 'Google User',
				'avatar'=>$r['profile_photo_url'] ?? '',
				'rating'=>$r['rating'] ?? 5,
				'text'=>$r['text'] ?? '',
				'time'=>!empty($r['time'])?(int)$r['time']:time(),
				'url'=>$js['result']['url'] ?? '',
			]);
		}
		// Google endpoint typically returns up to ~5 reviews.
		return array_slice($out,0,max(1,$limit));
	};

	$yelp_id_from_input=function($input){
		$input=trim((string)$input);
		if($input==='' )return '';
		if(filter_var($input,FILTER_VALIDATE_URL)){
			$p=wp_parse_url($input);
			if(!empty($p['path'])){
				$parts=array_values(array_filter(explode('/',$p['path'])));
				if(isset($parts[1])&&$parts[0]==='biz') return $parts[1];
			}
		}
		return $input;
	};
	$fetch_yelp=function($business_id,$api_key,$limit) use($http_get_json,$unify){
		if(!$business_id||!$api_key)return [];
		$hdrs=['headers'=>['Authorization'=>'Bearer '.$api_key]];
		$js=$http_get_json('https://api.yelp.com/v3/businesses/'.rawurlencode($business_id).'/reviews?sort_by=yelp_sort',$hdrs);
		if(!$js||empty($js['reviews']))return [];
		$out=[]; foreach($js['reviews'] as $r){
			$out[]=$unify([
				'id'=>($r['id'] ?? '').'_y','provider'=>'yelp',
				'author'=>$r['user']['name'] ?? 'Yelp User',
				'avatar'=>$r['user']['image_url'] ?? '',
				'rating'=>$r['rating'] ?? 5,
				'text'=>$r['text'] ?? '',
				'time'=>!empty($r['time_created'])?strtotime($r['time_created']):time(),
				'url'=>$r['url'] ?? '',
			]);
		}
		// Yelp endpoint is capped to ~3 reviews.
		return array_slice($out,0,max(1,$limit));
	};

	$fb_id_from_input=function($input){
		$input=trim((string)$input);
		if($input==='' )return '';
		if(filter_var($input,FILTER_VALIDATE_URL)){
			$p=wp_parse_url($input);
			if(!empty($p['path'])){
				$parts=array_values(array_filter(explode('/',$p['path'])));
				return $parts[0] ?? '';
			}
		}
		return $input;
	};

	// Facebook with pagination — keep fetching until we hit $limit or no next page
	$fetch_facebook=function($page_id,$access_token,$limit) use($http_get_json,$unify){
		if(!$page_id||!$access_token||$limit<1)return [];
		$collected=[];
		$url = add_query_arg([
			'fields'       => 'review_text,rating,created_time,reviewer{name,picture}',
			'limit'        => min(max(1,$limit), 50),
			'access_token' => $access_token,
		], 'https://graph.facebook.com/v19.0/'.rawurlencode($page_id).'/ratings');

		while ($url && count($collected) < $limit) {
			$js = $http_get_json($url);
			if(!$js || empty($js['data'])) break;

			foreach($js['data'] as $r){
				$collected[] = $unify([
					'id'     => md5(json_encode($r)).'_f',
					'provider'=>'facebook',
					'author' => $r['reviewer']['name'] ?? 'Facebook User',
					'avatar' => $r['reviewer']['picture']['data']['url'] ?? '',
					'rating' => $r['rating'] ?? 5,
					'text'   => $r['review_text'] ?? '',
					'time'   => !empty($r['created_time'])?strtotime($r['created_time']):time(),
					'url'    => 'https://facebook.com/'.rawurlencode($page_id).'/reviews',
				]);
				if (count($collected) >= $limit) break;
			}
			$url = !empty($js['paging']['next']) ? $js['paging']['next'] : '';
		}
		return $collected;
	};

	/* --------------- Fetch reviews --------------- */
	$reviews=[];
	if ( $source ) {
		$provider=strtolower($source['provider'] ?? 'google');
		$input   =$source['input'] ?? '';
		$api_ovr =$source['api_key'] ?? '';
		$ttl     = max(300,(int)$w['ttl']);

		$cache_input = $__place_id !== '' ? $__place_id : $input;
		$key='arw_rev_'.md5($provider.'|'.$cache_input.'|'.$w['language'].'|'.$w['limit'].'|'.$w['minRating'].'|'.(int)$w['hideNoText'].'|'.(int)($w['maxRating'] ?? 5));
		$cached=get_transient($key);
		if ( is_array($cached) ) {
			$reviews=$cached;
		} else {
			if($provider==='google'){
				$serp = $source['serp_api_key'] ?? ($settings['serpapi_key'] ?? '');
				$place_input = ($__place_id !== '') ? $__place_id : ($input ?: '');
				$pid = '';
				if (preg_match('/^[A-Za-z0-9\-_]{20,}$/',$place_input)) { $pid = $place_input; }
				else {
					$meta=$parse_maps_url($place_input);
					$params=['engine'=>'google_maps','type'=>'search','q'=>$meta['title'] ?: $place_input,'hl'=>$w['language'] ?: 'en'];
					if($meta['lat'] && $meta['lng']) $params['ll']='@'.$meta['lat'].','.$meta['lng'].',15z';
					$gm=$serp_get($params,$serp);
					$pid=$gm['place_results']['place_id'] ?? '';
				}
				$grevs=$serp_get(['engine'=>'google_maps_reviews','place_id'=>$pid,'hl'=>$w['language'] ?: 'en'],$serp);
				$tmp=[]; foreach((array)($grevs['reviews'] ?? []) as $r){
					$tmp[]=$unify([
						'id'=>($r['review_id'] ?? md5(json_encode($r))).'_g','provider'=>'google',
						'author'=>$r['user']['name'] ?? 'Google User','avatar'=>$r['user']['thumbnail'] ?? '',
						'rating'=>isset($r['rating'])?(int)$r['rating']:5,
						'text'=>$r['snippet'] ?? ($r['extracted_snippet']['original'] ?? ''),
						'time'=>!empty($r['iso_date'])?strtotime($r['iso_date']):time(),
						'url'=>$r['link'] ?? '',
					]);
				}
				$reviews = array_slice($tmp,0,$w['limit']);
			}elseif($provider==='yelp'){
				$serp = $source['serp_api_key'] ?? ($settings['serpapi_key'] ?? '');
				$meta=$parse_maps_url($input);
				$title=$meta['title'] ?: $input;
				$addr='';
				// Try to get address via Google place lookup to improve Yelp search
				$gm=$serp_get(['engine'=>'google_maps','type'=>'search','q'=>$title,'hl'=>$w['language'] ?: 'en'],$serp);
				$addr=$gm['place_results']['address'] ?? '';
				$ys=$serp_get(['engine'=>'yelp','find_desc'=>$title,'find_loc'=>$addr ?: 'New York, NY'],$serp);
				$place_id='';
				if(!empty($ys['organic_results'][0]['place_ids'][0])) $place_id=$ys['organic_results'][0]['place_ids'][0];
				elseif(!empty($ys['organic_results'][0]['link']) && preg_match('#/biz/([^?]+)#',$ys['organic_results'][0]['link'],$m)) $place_id=$m[1];
				$yrevs=$place_id ? $serp_get(['engine'=>'yelp_reviews','place_id'=>$place_id,'num'=>min($w['limit'],50)],$serp) : null;
				$tmp=[]; foreach((array)($yrevs['reviews'] ?? []) as $r){
					$tmp[]=$unify([
						'id'=>md5(json_encode($r)).'_y','provider'=>'yelp',
						'author'=>$r['user']['name'] ?? 'Yelp User','avatar'=>$r['user']['thumbnail'] ?? '',
						'rating'=>isset($r['rating'])?(int)$r['rating']:5,
						'text'=>$r['comment']['text'] ?? '',
						'time'=>!empty($r['date'])?strtotime($r['date']):time(),
						'url'=>$r['user']['link'] ?? '',
					]);
				}
				$reviews = array_slice($tmp,0,$w['limit']);
			}elseif($provider==='facebook'){
				$token=$api_ovr ?: $settings['facebook_access_token'];
				$id   =$fb_id_from_input($input ?: $settings['facebook_page_id']);
				$reviews=$fetch_facebook($id,$token,$w['limit']); // paginated
			}elseif($provider==='all'){
				$merged=[]; $serp = $source['serp_api_key'] ?? ($settings['serpapi_key'] ?? '');
				// Google
				$meta=$parse_maps_url($input); $params=['engine'=>'google_maps','type'=>'search','q'=>$meta['title'] ?: $input,'hl'=>$w['language'] ?: 'en'];
				if($meta['lat'] && $meta['lng']) $params['ll']='@'.$meta['lat'].','.$meta['lng'].',15z';
				$gm=$serp_get($params,$serp); $pid=$gm['place_results']['place_id'] ?? '';
				$grevs=$serp_get(['engine'=>'google_maps_reviews','place_id'=>$pid,'hl'=>$w['language'] ?: 'en'],$serp);
				foreach((array)($grevs['reviews'] ?? []) as $r){
					$merged[]=$unify([
						'id'=>($r['review_id'] ?? md5(json_encode($r))).'_g','provider'=>'google',
						'author'=>$r['user']['name'] ?? 'Google User','avatar'=>$r['user']['thumbnail'] ?? '',
						'rating'=>isset($r['rating'])?(int)$r['rating']:5,
						'text'=>$r['snippet'] ?? ($r['extracted_snippet']['original'] ?? ''),
						'time'=>!empty($r['iso_date'])?strtotime($r['iso_date']):time(),
						'url'=>$r['link'] ?? '',
					]);
				}
				// Yelp
				$addr=$gm['place_results']['address'] ?? '';
				$ys=$serp_get(['engine'=>'yelp','find_desc'=>$meta['title'] ?: $input,'find_loc'=>$addr ?: 'New York, NY'],$serp);
				$place_id='';
				if(!empty($ys['organic_results'][0]['place_ids'][0])) $place_id=$ys['organic_results'][0]['place_ids'][0];
				elseif(!empty($ys['organic_results'][0]['link']) && preg_match('#/biz/([^?]+)#',$ys['organic_results'][0]['link'],$m)) $place_id=$m[1];
				$yrevs=$place_id ? $serp_get(['engine'=>'yelp_reviews','place_id'=>$place_id,'num'=>min($w['limit'],50)],$serp) : null;
				foreach((array)($yrevs['reviews'] ?? []) as $r){
					$merged[]=$unify([
						'id'=>md5(json_encode($r)).'_y','provider'=>'yelp',
						'author'=>$r['user']['name'] ?? 'Yelp User','avatar'=>$r['user']['thumbnail'] ?? '',
						'rating'=>isset($r['rating'])?(int)$r['rating']:5,
						'text'=>$r['comment']['text'] ?? '',
						'time'=>!empty($r['date'])?strtotime($r['date']):time(),
						'url'=>$r['user']['link'] ?? '',
					]);
				}
				if(!empty($settings['facebook_access_token']) && !empty($settings['facebook_page_id'])){
					$merged=array_merge($merged,$fetch_facebook($settings['facebook_page_id'],$settings['facebook_access_token'],$w['limit']));
				}
				usort($merged,fn($a,$b)=> (int)$b['time'] <=> (int)$a['time']);
				$reviews=array_slice($merged,0,$w['limit']);
			}
			$reviews=array_values(array_filter($reviews,function($r)use($w){
				if($w['hideNoText'] && empty($r['text'])) return false;
				if((int)$r['rating'] < (int)$w['minRating']) return false;
				if(!empty($w['maxRating']) && (int)$r['rating'] > (int)$w['maxRating']) return false;
				return true;
			}));
			set_transient($key,$reviews,$ttl);
		}
	}

	if ( empty($reviews) ) {
		return '<div class="arw arw--empty">No reviews found. Check your Saved Source, API key/token and business ID/URL.</div>';
	}

	/* --------------- Render helpers --------------- */
	$stars_html=function($rating){ $rating=max(0,min(5,(int)$rating)); return str_repeat('★',$rating).'<span class="arw-dim">'.str_repeat('★',5-$rating).'</span>'; };
	$name_fmt=function($name)use($w){ $name=trim((string)$name);
		if($w['nameFormat']==='initial_last'){ $p=preg_split('/\s+/',$name); if(count($p)>=2) return strtoupper(substr($p[0],0,1)).'. '.end($p); return $name; }
		if($w['nameFormat']==='first_only'){ $p=preg_split('/\s+/',$name); return $p[0] ?? $name; }
		if($w['nameFormat']==='initials'){ $p=preg_split('/\s+/',$name); $i=''; foreach($p as $x) $i.=strtoupper(substr($x,0,1)); return $i ?: $name; }
		return $name;
	};
	$provider_logo=function($p)use($w){ if(empty($w['showPlatformLogos'])) return ''; $p=strtolower($p); return '<span class="arw-provider"><span class="arw-logo arw-logo--'.$p.'"></span><span class="arw-provider-name">'.ucfirst($p).'</span></span>'; };
	$fmt_date=function($ts)use($w){ $ts=(int)($ts ?: time()); return esc_html(date_i18n($w['dateFormat'] ?: 'Y-m-d',$ts)); };

	$card=function($r)use($w,$stars_html,$name_fmt,$provider_logo,$fmt_date){
		$avatar='';
		if($w['showAvatar']){
			if($w['avatarLocal'] || empty($r['avatar'])){
				$avatar='<div class="arw-avatar arw-avatar--local">'.esc_html(strtoupper(substr($r['author'],0,1))).'</div>';
			}else{
				$avatar='<img class="arw-avatar" src="'.esc_url($r['avatar']).'" alt="" loading="lazy">';
			}
		}
		$providerMark=$provider_logo($r['provider']);
		$verified=$w['showVerifiedIcon']?'<span class="arw-verified">✔</span>':'';
		$reply='';
		if($w['showReply'] && !empty($r['reply'])) $reply='<div class="arw-reply"><strong>Owner reply:</strong> '.esc_html($r['reply']).'</div>';

		$text=esc_html($r['text']);
		$more=$w['reviewText']?'<button class="arw-more" type="button" data-more="'.esc_attr($w['reviewText']).'" data-less="Read less" hidden>'.esc_html($w['reviewText']).'</button>':'';

		$link_start=$r['url']?'<a class="arw-review-link" href="'.esc_url($r['url']).'" target="_blank" rel="nofollow noopener">':'';
		$link_end  =$r['url']?'</a>':'';

		return '
		<article class="arw-card" data-bg="light">
			<header class="arw-head">
				'.$avatar.'
				<div class="arw-meta">
					<div class="arw-author">'.$link_start.$name_fmt($r['author']).$link_end.' '.$verified.'</div>
					<div class="arw-sub"><span class="arw-stars">'.$stars_html($r['rating']).'</span> · <span class="arw-date">'.$fmt_date($r['time']).'</span> '.$providerMark.'</div>
				</div>
			</header>
			<div class="arw-body">
				<p class="arw-text" data-collapsed="1">'.$text.'</p>'.$more.'
			</div>
			'.$reply.'
		</article>';
	};

	$items=''; foreach($reviews as $r) $items.=$card($r);

	$minline = !empty($w['showMinFilter']) ? '<div class="arw-min-note">Showing reviews rated '.$w['minRating'].'–'.($w['maxRating'] ?? 5).'★</div>' : '';
	$nav = ($w['showNav'] && $w['layout']==='slider')
		? '<button class="arw-btn arw-prev arw-nav arw-nav--prev" type="button" aria-label="Previous">‹</button><button class="arw-btn arw-next arw-nav arw-nav--next" type="button" aria-label="Next">›</button>'
		: '';

	$classes=['arw','arw--style-'.$w['style'],'arw--layout-'.$w['layout'],'arw--align-'.$w['align']];
	if($w['hoverAnim']) $classes[]='arw--hover';
	if($w['useSiteFont']) $classes[]='arw--use-site-font';

	$html='
	<div class="'.esc_attr(implode(' ',$classes)).'">
		'.$minline.'
		<div class="arw-viewport">
			<div class="arw-wrap arw-track">'.$items.'</div>
			'.$nav.'
		</div>
	</div>';

	$style='
	<style>
	.arw{--arw-bg:#fff;--arw-fg:#111;--arw-dim:#6b7280;--arw-card:#f3f4f6;--arw-bd:#e5e7eb;--arw-star:#f59e0b;--gap:16px}
	.arw.arw--style-dark{--arw-bg:#0f172a;--arw-fg:#e5e7eb;--arw-dim:#94a3b8;--arw-card:#1f2937;--arw-bd:#374151}
	.arw{color:var(--arw-fg)}
	.arw .arw-viewport{position:relative; overflow:hidden}
	.arw .arw-wrap{display:flex;gap:var(--gap);will-change:transform;transition:transform .45s ease}
	/* 3/2/1 per view */
	.arw .arw-card{flex:0 0 calc((100% - (var(--gap) * 2)) / 3); background:var(--arw-card); border:1px solid var(--arw-bd);border-radius:16px;padding:12px}
	@media(max-width:900px){ .arw .arw-card{flex:0 0 calc((100% - var(--gap)) / 2)} }
	@media(max-width:600px){ .arw .arw-card{flex:0 0 100%} }
	.arw .arw-head{display:flex;gap:10px;align-items:center;margin-bottom:6px}
	.arw .arw-avatar{width:36px;height:36px;border-radius:999px;object-fit:cover;background:#ddd;display:inline-block}
	.arw .arw-avatar--local{background:#ef4444;color:#fff;font-weight:700;display:grid;place-items:center}
	.arw .arw-author{font-weight:700}
	.arw .arw-sub{font-size:12px;color:var(--arw-dim)}
	.arw .arw-stars{color:var(--arw-star)}
	.arw .arw-text{margin:8px 0 0; overflow:hidden; display:-webkit-box; -webkit-box-orient:vertical}
	.arw .arw-text[data-collapsed="1"]{-webkit-line-clamp:3; max-height:4.5em}
	.arw .arw-text[data-collapsed="0"]{display:block; max-height:none; -webkit-line-clamp:unset}
	.arw .arw-more{margin-top:6px;background:transparent;border:0;color:inherit;opacity:.7;cursor:pointer;padding:0;text-decoration:underline;text-underline-offset:2px}
	.arw .arw-more:hover{opacity:.95}
	.arw.arw--hover .arw-card:hover{box-shadow:0 10px 16px rgba(0,0,0,.08);transform:translateY(-1px)}
	/* provider marks */
	.arw .arw-logo{display:inline-block;width:12px;height:12px;border-radius:3px;background:#999;margin:0 4px;vertical-align:middle}
	.arw .arw-logo--google{background:#4285F4}
	.arw .arw-logo--yelp{background:#d32323}
	.arw .arw-logo--facebook{background:#1773ea}
	.arw .arw-provider-name{margin-left:2px;color:var(--arw-dim)}
	.arw .arw-verified{color:#22c55e;margin-left:4px}
	/* nav buttons */
	.arw .arw-btn{position:absolute;top:50%;transform:translateY(-50%);z-index:3;width:44px;height:44px;border-radius:999px;border:1px solid var(--arw-bd);background:#fff;cursor:pointer;display:grid;place-items:center;box-shadow:0 2px 10px rgba(0,0,0,.10);transition:box-shadow .2s ease, background .2s ease}
	.arw .arw-btn:hover{background:#e5e7eb;box-shadow:0 4px 14px rgba(0,0,0,.12)}
	.arw .arw-prev{left:-6px}
	.arw .arw-next{right:-6px}
	.arw .arw-btn[disabled]{opacity:.35;cursor:default}
	.arw .arw-min-note{margin:4px 0 8px;color:var(--arw-dim);font-size:12px}
	</style>';

	$js='
	<script>
	(function(){
	  var roots = document.querySelectorAll(".arw");
	  roots.forEach(function(root){
	    var track = root.querySelector(".arw-track");
	    var prev = root.querySelector(".arw-prev");
	    var next = root.querySelector(".arw-next");
	    if(!track){ return; }

		let index = 0;
		let autoTimer = null;

		function cardsPerView(){
		  var w = root.clientWidth;
		  if(w <= 600) return 1;
		  if(w <= 900) return 2;
		  return 3;
		}
		function stepSize(){
		  var first = track.children[0];
		  if(!first) return 0;
		  var gap = parseFloat(getComputedStyle(root).getPropertyValue("--gap")) || 16;
		  var rect = first.getBoundingClientRect();
		  return rect.width + gap;
		}
		function maxIndex(){
		  var total = track.children.length;
		  var cpv = cardsPerView();
		  return Math.max(0, total - cpv);
		}
		function update(){
		  var x = -index * stepSize();
		  track.style.transform = "translateX(" + x + "px)";
		  if(prev) prev.disabled = index <= 0;
		  if(next) next.disabled = index >= maxIndex();
		}

		if(prev) prev.addEventListener("click", function(){ if(index>0){ index--; update(); restartAuto(); } });
		if(next) next.addEventListener("click", function(){ if(index<maxIndex()){ index++; update(); restartAuto(); } });

		// Auto-advance every 2s; pause on hover
		function startAuto(){
		  if(autoTimer) return;
		  autoTimer = setInterval(function(){
		    if(index < maxIndex()) index++; else index = 0;
		    update();
		  }, 2000);
		}
		function stopAuto(){ if(autoTimer){ clearInterval(autoTimer); autoTimer=null; } }
		function restartAuto(){ stopAuto(); startAuto(); }
		root.addEventListener("mouseenter", stopAuto);
		root.addEventListener("mouseleave", startAuto);

		// Read-more only when text overflows
		track.querySelectorAll(".arw-card").forEach(function(card){
		  var p = card.querySelector(".arw-text");
		  var btn = card.querySelector(".arw-more");
		  if(!p || !btn) return;
		  requestAnimationFrame(function(){
		    var isOverflow = p.scrollHeight > p.clientHeight + 2;
		    if(isOverflow){ btn.hidden = false; }
		  });
		  btn.addEventListener("click", function(){
		    var collapsed = p.getAttribute("data-collapsed") === "1";
		    p.setAttribute("data-collapsed", collapsed ? "0" : "1");
		    var more = btn.getAttribute("data-more") || "Read more";
		    var less = btn.getAttribute("data-less") || "Read less";
		    btn.textContent = collapsed ? less : more;
		  });
		});

		window.addEventListener("resize", function(){
		  index = Math.min(index, maxIndex());
		  update();
		}, {passive:true});

		update();
		startAuto();
	  });
	})();
	</script>';

	return $style.$html.$js;
};

/* Editor preview helper (unchanged) */
function arw_fake_cards_html($count=3,$style='light'){
	$names=['A. Kumar','S. Rao','M. Singh','J. Perez','D. Hill','N. Chen'];
	$txts=['Amazing service and super friendly staff!','Great ambience. Will visit again.','Five stars. Highly recommended!','Quick, professional, and helpful.','Lovely place with attentive staff.'];
	$out='<div class="arw arw--style-'.esc_attr($style).' arw--layout-list"><div class="arw-wrap">';
	for($i=0;$i<$count;$i++){
		$out.='<article class="arw-card"><header class="arw-head"><div class="arw-avatar arw-avatar--local">A</div><div class="arw-meta"><div class="arw-author">'.$names[$i%count($names)].' <span class="arw-verified">✔</span></div><div class="arw-sub"><span class="arw-stars">★★★★★</span> · <span class="arw-date">recent</span> <span class="arw-provider"><span class="arw-logo arw-logo--google"></span><span class="arw-provider-name">Google</span></span></div></div></header><div class="arw-body"><p class="arw-text" data-collapsed="0">'.$txts[$i%count($txts)].'</p></div></article>';
	}
	$out.='</div></div>'; return $out;
}
