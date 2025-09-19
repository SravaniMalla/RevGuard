<?php
/**
 * Simple shortcode wrapper for the Multi-Source Reviews block.
 * Shortcode: [multi_reviews limit="6" min_rating="4" layout="cards"]
 */
if (!defined('ABSPATH')) exit;

add_shortcode('multi_reviews', function($atts){
  $atts = shortcode_atts([
    'limit'      => 6,
    'min_rating' => 4,
    'layout'     => 'cards'
  ], $atts, 'multi_reviews');

  // If the block is registered, render it; if not, call the plugin's PHP renderer.
  if (function_exists('render_block')) {
    return render_block([
      'blockName' => 'awesome/multi-source-reviews',
      'attrs' => [
        'limit'     => (int)$atts['limit'],
        'minRating' => (int)$atts['min_rating'],
        'layout'    => $atts['layout']
      ],
      'innerHTML' => ''
    ]);
  }

  // Fallback: call your plugin's server renderer directly if available.
  if (class_exists('MSR_Aggregator')) {
    $opts = get_option('msr_settings', []);
    $providers = [];
    if (class_exists('MSR_Provider_Google'))   $providers[] = new MSR_Provider_Google($opts);
    if (class_exists('MSR_Provider_Yelp'))     $providers[] = new MSR_Provider_Yelp($opts);
    if (class_exists('MSR_Provider_Facebook')) $providers[] = new MSR_Provider_Facebook($opts);
    $aggr = new MSR_Aggregator($providers);
    $reviews = $aggr->get_reviews((int)$atts['limit'], (int)$atts['min_rating']);
    ob_start();
    include WP_PLUGIN_DIR.'/reviews/templates/reviews-list.php';
    return ob_get_clean();
  }

  return '<p>Multi-Source Reviews: renderer not found.</p>';
});
