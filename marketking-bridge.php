<?php
/**
 * Plugin Name: MarketKing Bridge (FluxStore)
 * Description: REST endpoints to let FluxStore read MarketKing vendor data.
 * Version: 0.2.0
 * Author: ArtQart
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('MKB_Plugin') ) {

  final class MKB_Plugin {

    public static function init() {
      add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
      register_rest_route('mk/v1', '/ping', [
        'methods'  => 'GET',
        'callback' => [__CLASS__, 'ping'],
        'permission_callback' => '__return_true',
      ]);

      // List vendors (role filter optional; falls back to authors of published products)
      register_rest_route('mk/v1', '/vendors', [
        'methods'  => 'GET',
        'callback' => [__CLASS__, 'vendors'],
        'permission_callback' => '__return_true',
        'args' => [
          'per_page' => ['default' => 20],
          'page'     => ['default' => 1],
          // Accepts string or array: role=marketking_vendor or role[]=marketking_vendor
          'role'     => [],
        ],
      ]);
      
      register_rest_route('mk/v1', '/vendors/by-product/(?P<product_id>\d+)', [
  'methods'  => 'GET',
  'callback' => [__CLASS__, 'vendor_by_product'],
  'permission_callback' => '__return_true',
]);

      // Single vendor detail
      register_rest_route('mk/v1', '/vendors/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => [__CLASS__, 'vendor_detail'],
        'permission_callback' => '__return_true',
      ]);

      // Vendor products
      register_rest_route('mk/v1', '/vendors/(?P<id>\d+)/products', [
        'methods'  => 'GET',
        'callback' => [__CLASS__, 'vendor_products'],
        'permission_callback' => '__return_true',
        'args' => [
          'page'      => ['default' => 1],
          'per_page'  => ['default' => 20],
          'orderby'   => ['default' => 'date'],
          'order'     => ['default' => 'DESC'],
          'status'    => ['default' => 'publish'], // comma list allowed
        ],
      ]);

      // Vendor reviews
      register_rest_route('mk/v1', '/vendors/(?P<id>\d+)/reviews', [
        'methods'  => 'GET',
        'callback' => [__CLASS__, 'vendor_reviews'],
        'permission_callback' => '__return_true',
        'args' => [
          'page'      => ['default' => 1],
          'per_page'  => ['default' => 20],
        ],
      ]);

      // Vendor coupons (NEW)
      register_rest_route('mk/v1', '/vendors/(?P<id>\d+)/coupons', [
        'methods'  => 'GET',
        'callback' => [__CLASS__, 'vendor_coupons'],
        'permission_callback' => '__return_true',
      ]);
      

// /mk/v1/vendors/search?q=term
register_rest_route('mk/v1','/vendors/search',[
  'methods'  => 'GET',
  'callback' => [__CLASS__,'vendors_search'],
  'permission_callback' => '__return_true',
  'args' => [
    'q'          => ['required' => true],
    'page'       => ['default' => 1],
    'per_page'   => ['default' => 20],
    'with_products_only' => ['default' => 1], // 1 = only vendors that have published products
  ],
]);

// /mk/v1/vendors/sections?limit=12
register_rest_route('mk/v1','/vendors/sections',[
  'methods'  => 'GET',
  'callback' => [__CLASS__,'vendors_sections'],
  'permission_callback' => '__return_true',
  'args' => [
    'limit'     => ['default' => 12],
    'with_products_only' => ['default' => 1],
  ],
]);


    }

    /* ---------------- Handlers ---------------- */

    public static function ping( WP_REST_Request $req ) {
      return new WP_REST_Response(['ok'=>true,'time'=>current_time('mysql')], 200);
    }

    /**
     * Vendors list:
     * - If role provided (string or role[]), filter by role.
     * - Else, auto-discover by product authors having at least one published product.
     */
    public static function vendors( WP_REST_Request $req ) {
  global $wpdb;

  $per_page = max(1, (int) $req['per_page']);
  $page     = max(1, (int) $req['page']);
  $role_in  = $req->get_param('role');

  $users  = [];
  $total  = 0;
  $counts = []; // vendor_id => products_count (publish)

  // Path A: explicit role(s) provided -> list all users with that role
  if ( ! empty( $role_in ) ) {
    $roles = is_array($role_in) ? array_map('sanitize_text_field', $role_in)
                                : [ sanitize_text_field($role_in) ];

    $q = new WP_User_Query([
      'role__in' => $roles,
      'number'   => $per_page,
      'paged'    => $page,
      'orderby'  => 'display_name',
      'order'    => 'ASC',
      'fields'   => ['ID','display_name','user_email','user_login'],
    ]);

    $users = (array) $q->get_results();
    $total = method_exists($q, 'get_total') ? (int) $q->get_total() : 0;

    // Optional: compute counts only if you want them in this path too (heavier).
    // For big sites, consider skipping or caching this:
    // foreach ($users as $u) {
    //   $counts[$u->ID] = (int) $wpdb->get_var($wpdb->prepare(
    //     "SELECT COUNT(*) FROM {$wpdb->posts}
    //      WHERE post_type='product' AND post_status='publish' AND post_author=%d",
    //     $u->ID
    //   ));
    // }

  } else {
    // Path B: no role -> discover vendors by authors of *published* products
    // Get author counts + order by count DESC for a meaningful sort
    $rows = $wpdb->get_results("
      SELECT post_author AS uid, COUNT(*) AS cnt
      FROM {$wpdb->posts}
      WHERE post_type='product' AND post_status='publish'
      GROUP BY post_author
      ORDER BY cnt DESC
    ");

    $all_ids = [];
    foreach ((array)$rows as $r) {
      $vid = (int) $r->uid;
      $all_ids[] = $vid;
      $counts[$vid] = (int) $r->cnt;
    }

    $total  = count($all_ids);
    $offset = ($page - 1) * $per_page;
    $slice  = array_slice($all_ids, $offset, $per_page);

    // Preserve our order (by product count desc)
    $users = !empty($slice) ? get_users([
      'include' => $slice,
      'orderby' => 'include',
      'fields'  => ['ID','display_name','user_email','user_login'],
    ]) : [];
  }

  // Map to JSON and inject products_count when we have it
  $items = array_map(function($u) use ($counts) {
    $row = self::vendor_json($u);
    if ($row && isset($row['id']) && isset($counts[$row['id']])) {
      $row['products_count'] = (int) $counts[$row['id']];
    }
    return $row;
  }, $users);

  $resp = rest_ensure_response([
    'page'     => $page,
    'per_page' => $per_page,
    'total'    => (int) $total,
    'vendors'  => array_values($items),
  ]);
  $resp->header('X-WP-Total', (string) $total);
  $resp->header('X-WP-TotalPages', (string) max(1, ceil($total / max(1,$per_page))));
  return $resp;
}


    public static function vendor_detail( WP_REST_Request $req ) {
      $id = (int) $req['id'];
      $u = get_user_by('id', $id);
      if ( ! $u ) {
        return new WP_Error('mk_not_found', 'Vendor not found', ['status'=>404]);
      }
      return rest_ensure_response( self::vendor_json($u) );
    }

    public static function vendor_products( WP_REST_Request $req ) {
      if ( ! function_exists('wc_get_products') ) {
        return new WP_Error('mk_wc_missing', 'WooCommerce not active', ['status'=>500]);
      }

      $vendor_id = (int) $req['id'];
      $page = max(1, (int) $req['page']);
      $per  = min(50, max(1, (int) $req['per_page']));
      $orderby = sanitize_text_field( $req['orderby'] ?: 'date' );
      $order   = strtoupper(sanitize_text_field( $req['order'] ?: 'DESC' ));
      $order   = in_array($order, ['ASC','DESC'], true) ? $order : 'DESC';
      $status  = $req->get_param('status');
      $statuses = array_filter(array_map('trim', explode(',', (string)$status ?: 'publish')));

      // IDs page
      $ids = wc_get_products([
        'status'  => $statuses,
        'limit'   => $per,
        'page'    => $page,
        'orderby' => $orderby,
        'order'   => $order,
        'author'  => $vendor_id,
        'return'  => 'ids',
      ]);

      // Total
      $count_ids = wc_get_products([
        'status' => $statuses,
        'limit'  => -1,
        'author' => $vendor_id,
        'return' => 'ids',
      ]);
      $total = is_array($count_ids) ? count($count_ids) : 0;

      $products = array_values(array_filter(array_map([__CLASS__, 'product_minimal_json'], (array)$ids)));

      $resp = rest_ensure_response($products);
      $resp->header('X-WP-Total', (string)$total);
      $resp->header('X-WP-TotalPages', (string)ceil($total / max(1,$per)));
      return $resp;
    }

    public static function vendor_reviews( WP_REST_Request $req ) {
      $vendor_id = (int) $req['id'];
      $page = max(1, (int) $req['page']);
      $per  = min(50, max(1, (int) $req['per_page']));

      $args = [
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_author' => $vendor_id,
        'status'      => 'approve',
        'number'      => $per,
        'paged'       => $page,
      ];

      $comments = get_comments($args);
      $total = (int) get_comments( array_merge($args, ['count' => true, 'number' => 0]) );

      $reviews = array_map(function($c){
        return [
          'id'          => (int) $c->comment_ID,
          'author_name' => $c->comment_author,
          'content'     => wpautop($c->comment_content),
          'rating'      => (float) get_comment_meta($c->comment_ID, 'rating', true),
          'date'        => gmdate('c', strtotime($c->comment_date_gmt)),
          'product_id'  => (int) $c->comment_post_ID,
        ];
      }, $comments);

      $resp = rest_ensure_response($reviews);
      $resp->header('X-WP-Total', (string)$total);
      $resp->header('X-WP-TotalPages', (string)ceil($total / max(1,$per)));
      return $resp;
    }

    public static function vendor_coupons( WP_REST_Request $req ) {
      $vendor_id = (int) $req['id'];

      $coupons = get_posts([
        'post_type'   => 'shop_coupon',
        'post_status' => 'any',
        'numberposts' => -1,
        'author'      => $vendor_id,
        'fields'      => 'ids'
      ]);

      $data = array_map(function($cid){
        $code  = get_post_field('post_title', $cid);
        $type  = get_post_meta($cid, 'discount_type', true);
        $amt   = get_post_meta($cid, 'coupon_amount', true);
        $usage = (int) get_post_meta($cid, 'usage_count', true);
        $exp   = get_post_meta($cid, 'date_expires', true);
        return [
          'id'            => (int) $cid,
          'code'          => $code,
          'discount_type' => $type,
          'amount'        => $amt,
          'usage_count'   => $usage,
          'date_expires'  => $exp ? gmdate('c', (int) $exp) : null,
        ];
      }, (array) $coupons);

      return rest_ensure_response($data);
    }
public static function vendor_by_product( WP_REST_Request $req ) {
  if ( ! function_exists('wc_get_product') ) {
    return new WP_Error('mk_wc_missing', 'WooCommerce not active', ['status'=>500]);
  }

  $pid = (int) $req['product_id'];
  $product = wc_get_product($pid);

  // If a variation ID is passed, resolve to parent product (has the author)
  if ( $product && $product->is_type('variation') ) {
    $parent_id = $product->get_parent_id();
    if ( $parent_id ) {
      $product = wc_get_product($parent_id);
    }
  }

  if ( ! $product ) {
    return new WP_Error('mk_not_found', 'Product not found', ['status'=>404]);
  }

  // Author = vendor
  $post = get_post( $product->get_id() );
  if ( ! $post ) {
    return new WP_Error('mk_not_found', 'Product post not found', ['status'=>404]);
  }

  $vendor_id = (int) $post->post_author;
  $vendor = get_user_by('id', $vendor_id);
  if ( ! $vendor ) {
    return new WP_Error('mk_not_found', 'Vendor not found', ['status'=>404]);
  }

  // Build response using your existing vendor_json()
  $data = self::vendor_json($vendor);

  // (Optional) include quick links the app might use
  $data['links'] = [
    'author_archive' => get_author_posts_url($vendor_id), // fallback “store” URL
    'api_self'       => rest_url( sprintf('mk/v1/vendors/%d', $vendor_id) ),
    'api_products'   => rest_url( sprintf('mk/v1/vendors/%d/products', $vendor_id) ),
  ];

  $resp = rest_ensure_response($data);
  $resp->set_headers([
    'Cache-Control' => 'public, max-age=60',
  ]);
  return $resp;
}

public static function vendors_search( WP_REST_Request $req ) {
  $q      = sanitize_text_field( $req['q'] );
  $page   = max(1,(int)$req['page']);
  $per    = max(1,(int)$req['per_page']);
  $onlyWP = (int)$req->get_param('with_products_only') === 1;

  // Base user search by core columns
  $uq = new WP_User_Query([
    'search'         => '*'.esc_attr($q).'*',
    'search_columns' => ['user_login','user_nicename','display_name','user_email'],
    'number'         => $per,
    'paged'          => $page,
    'fields'         => ['ID','display_name','user_email','user_login'],
    'orderby'        => 'display_name',
    'order'          => 'ASC',
  ]);
  $users = (array) $uq->get_results();

  // Also search store name meta (billing_company)
  $meta_ids = get_users([
    'meta_key'     => 'billing_company',
    'meta_value'   => $q,
    'meta_compare' => 'LIKE',
    'fields'       => 'ID',
  ]);

  // Merge unique IDs
  $ids = array_unique(array_merge(
    array_map(fn($u)=> (int)$u->ID, $users),
    array_map('intval',(array)$meta_ids)
  ));

  // Optional filter: vendors that have at least 1 published product
  if ($onlyWP && ! empty($ids)) {
    $ids = array_values(array_filter($ids, function($vid){
      return self::vendor_has_published_products($vid);
    }));
  }

  $total = count($ids);
  $slice = array_slice($ids, ($page-1)*$per, $per);
  $result_users = !empty($slice)
    ? get_users(['include'=>$slice,'fields'=>['ID','display_name','user_email','user_login']])
    : [];

  $data = array_values(array_filter(array_map([__CLASS__,'vendor_json'], $result_users)));

  $resp = rest_ensure_response([
    'page'     => $page,
    'per_page' => $per,
    'total'    => $total,
    'vendors'  => $data,
  ]);
  $resp->header('X-WP-Total', (string)$total);
  $resp->header('X-WP-TotalPages', (string)max(1,ceil($total/$per)));
  return $resp;
}

public static function vendors_sections( WP_REST_Request $req ) {
  $limit  = max(1,(int)$req['limit']);
  $onlyWP = (int)$req->get_param('with_products_only') === 1;

  // -------- Featured (meta marketking_featured = 1) --------
  $featured = get_users([
    'meta_key'   => 'marketking_featured',
    'meta_value' => '1',
    'number'     => $limit * 2, // overfetch a bit then trim by product filter
    'orderby'    => 'display_name',
    'order'      => 'ASC',
    'fields'     => ['ID','display_name','user_email','user_login'],
  ]);
  if ($onlyWP) {
    $featured = array_values(array_filter($featured, fn($u)=> self::vendor_has_published_products($u->ID)));
  }
  $featured = array_slice($featured, 0, $limit);
  $featured = array_values(array_filter(array_map([__CLASS__,'vendor_json'], $featured)));

  // -------- New vendors (by registration date) --------
  $newbies = get_users([
    'number'  => $limit * 2,
    'orderby' => 'registered',
    'order'   => 'DESC',
    'fields'  => ['ID','display_name','user_email','user_login'],
  ]);
  if ($onlyWP) {
    $newbies = array_values(array_filter($newbies, fn($u)=> self::vendor_has_published_products($u->ID)));
  }
  $newbies = array_slice($newbies, 0, $limit);
  $newbies = array_values(array_filter(array_map([__CLASS__,'vendor_json'], $newbies)));

  // -------- Top-rated (avg rating across their products) --------
  $top_candidates = get_users([
    'number'  => $limit * 6, // overfetch; we’ll rank & trim
    'fields'  => ['ID','display_name','user_email','user_login'],
  ]);
  if ($onlyWP) {
    $top_candidates = array_values(array_filter($top_candidates, fn($u)=> self::vendor_has_published_products($u->ID)));
  }

  $ranked = [];
  foreach ($top_candidates as $u) {
    $ranked[] = [
      'user'   => $u,
      'rating' => self::get_vendor_rating($u->ID),
    ];
  }
  usort($ranked, fn($a,$b)=> $b['rating'] <=> $a['rating']);
  $top = array_slice($ranked, 0, $limit);
  $top = array_values(array_map(fn($r)=> self::vendor_json($r['user']) + ['_avg_rating'=>$r['rating']], $top));

  return rest_ensure_response([
    'featured'   => $featured,
    'new'        => $newbies,
    'top_rated'  => $top,
  ]);
}


    /* ---------------- Helpers ---------------- */

    private static function vendor_json( $u ) {
      if ($u instanceof WP_User) {
        $id   = (int) $u->ID;
        $name = $u->display_name;
        $email= $u->user_email;
      } elseif (is_object($u) && isset($u->ID)) {
        $id   = (int) $u->ID;
        $name = isset($u->display_name) ? $u->display_name : '';
        $email= isset($u->user_email) ? $u->user_email : '';
      } elseif (is_array($u) && isset($u['ID'])) {
        $id   = (int) $u['ID'];
        $name = isset($u['display_name']) ? $u['display_name'] : '';
        $email= isset($u['user_email']) ? $u['user_email'] : '';
      } else {
        return null;
      }

      $store = get_user_meta($id, 'billing_company', true);
      if (empty($store)) {
        $store = get_user_meta($id, 'marketking_store_name', true);
        if (empty($store)) $store = $name;
      }

      return [
        'id'           => $id,
        'display_name' => $name,
        'email'        => $email,
        'store_name'   => $store,
        'products_count' => (int) count_user_posts($id, 'product', true),
        'phone'        => get_user_meta($id, 'billing_phone', true),
        'address_1'    => get_user_meta($id, 'billing_address_1', true),
        'address_2'    => get_user_meta($id, 'billing_address_2', true),
        'logo'         => get_user_meta($id, 'marketking_profile_logo_image', true) ?: get_avatar_url($id),
        'banner'       => get_user_meta($id, 'marketking_profile_logo_image_banner', true),
        'policy'       => get_user_meta($id, 'marketking_policy_message', true),
        'social'       => array_filter([
          'twitter'   => get_user_meta($id, 'marketking_twitter', true),
          'tiktok'    => get_user_meta($id, 'marketking_tiktok', true),
          'pinterest' => get_user_meta($id, 'marketking_pinterest', true),
          'facebook'  => get_user_meta($id, 'marketking_facebook', true),
          'instagram' => get_user_meta($id, 'marketking_instagram', true),
          'youtube'   => get_user_meta($id, 'marketking_youtube', true),
        ]),
      ];
    }

    private static function product_minimal_json( $id ) {
      if ( ! function_exists('wc_get_product') ) return null;
      $p = wc_get_product($id);
      if ( ! $p ) return null;

      $images = [];
      $thumb = $p->get_image_id();
      $gallery = $p->get_gallery_image_ids();
      $img_ids = array_unique(array_filter(array_merge([$thumb], (array)$gallery)));
      foreach ($img_ids as $mid) {
        $url = wp_get_attachment_image_url($mid, 'full');
        if ($url) $images[] = ['id' => (int)$mid, 'src' => $url];
      }

      return [
        'id'             => (int)$p->get_id(),
        'name'           => $p->get_name(),
        'slug'           => $p->get_slug(),
        'type'           => $p->get_type(),
        'price'          => (string)$p->get_price(),
        'regular_price'  => (string)$p->get_regular_price(),
        'sale_price'     => (string)$p->get_sale_price(),
        'on_sale'        => (bool)$p->is_on_sale(),
        'stock_status'   => $p->get_stock_status(),
        'average_rating' => (float)$p->get_average_rating(),
        'rating_count'   => (int)$p->get_rating_count(),
        'images'         => $images,
      ];
    }
    
    private static function vendor_has_published_products( $vendor_id ) {
  if ( ! function_exists('wc_get_products') ) return false;
  $ids = wc_get_products([
    'status'  => ['publish'],
    'limit'   => 1,
    'author'  => (int)$vendor_id,
    'return'  => 'ids',
  ]);
  return ! empty($ids);
}

/**
 * Average rating across all published products by vendor.
 * Cached for 10 minutes via transient: mkb_vendor_rating_{id}
 */
private static function get_vendor_rating( $vendor_id ) {
  if ( ! function_exists('wc_get_products') ) return 0.0;

  $key = 'mkb_vendor_rating_' . (int)$vendor_id;
  $cached = get_transient($key);
  if ($cached !== false) return (float)$cached;

  $ids = wc_get_products([
    'status' => ['publish'],
    'limit'  => -1,
    'author' => (int)$vendor_id,
    'return' => 'ids',
  ]);
  if (empty($ids)) {
    set_transient($key, 0.0, 10 * MINUTE_IN_SECONDS);
    return 0.0;
  }
  $sum = 0.0; $cnt = 0;
  foreach ((array)$ids as $pid) {
    $p = wc_get_product($pid);
    if (!$p) continue;
    $r = (float)$p->get_average_rating();
    if ($r > 0) { $sum += $r; $cnt++; }
  }
  $avg = $cnt ? $sum / $cnt : 0.0;
  set_transient($key, $avg, 10 * MINUTE_IN_SECONDS);
  return $avg;
}

  }

  MKB_Plugin::init();
}
