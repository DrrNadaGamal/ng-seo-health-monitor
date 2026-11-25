<?php
/**
 * Plugin Name: NG SEO Health Monitor
 * Description: Logs 404 errors with hit counts (no IP address, no user agent stored), suggests a 301 redirect to the closest matching post by slug, redirects only after you approve, and finds broken local images in your posts. Part of the NG WordPress Suite.
 * Version: 0.1.0
 * Author: Nada Gamal
 * License: GPL-2.0-or-later
 * Text Domain: ng-shm
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NGSHM {
	const OPT = 'ngshm_settings';
	const IMG = 'ngshm_images';
	static function defaults() { return array( 'log' => 1, 'referrer' => 0, 'max_rows' => 5000, 'keep_days' => 90, 'min_score' => 60, 'ignore_ext' => 'jpg,jpeg,png,gif,webp,svg,ico,css,js,map,woff,woff2,ttf,txt,xml' ); }
	static function s() { return wp_parse_args( get_option( self::OPT, array() ), self::defaults() ); }
	static function t404() { global $wpdb; return $wpdb->prefix . 'ngshm_404'; }
	static function tred() { global $wpdb; return $wpdb->prefix . 'ngshm_redirects'; }

	static function install() {
		global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $c = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE " . self::t404() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path_hash char(32) NOT NULL,
			path varchar(255) NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 1,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			referrer_host varchar(120) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'open',
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash)
		) $c;" );
		dbDelta( "CREATE TABLE " . self::tred() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			from_hash char(32) NOT NULL,
			from_path varchar(255) NOT NULL,
			to_url varchar(500) NOT NULL,
			code smallint(3) NOT NULL DEFAULT 301,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY from_hash (from_hash)
		) $c;" );
		if ( ! wp_next_scheduled( 'ngshm_purge' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ngshm_purge' ); }
	}

	static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log' ), 99 );
		add_action( 'ngshm_purge', array( __CLASS__, 'purge' ) );
		add_action( 'admin_menu', function () { add_management_page( 'NG SEO Health Monitor', __( 'SEO Health', 'ng-shm' ), 'manage_options', 'ngshm', array( __CLASS__, 'page' ) ); } );
		foreach ( array( 'approve', 'ignore', 'delete404', 'deleteredirect', 'scanimages', 'save', 'addredirect' ) as $a ) { add_action( 'admin_post_ngshm_' . $a, array( __CLASS__, 'handle_' . $a ) ); }
		add_filter( 'gettext_ng-shm', function ( $t ) { $m = self::T(); $l = substr( apply_filters( 'ngshm_locale', determine_locale() ), 0, 2 ); return isset( $m[ $l ][ $t ] ) ? $m[ $l ][ $t ] : $t; } );
	}
	static function T() { return array(
		'ar' => array( 'SEO Health' => 'صحة السيو', '404 log' => 'سجل 404', 'Redirects' => 'التحويلات', 'Broken images' => 'الصور المكسورة', 'Settings' => 'الإعدادات', 'Approve 301' => 'اعتماد تحويل 301', 'Ignore' => 'تجاهل', 'Delete' => 'حذف', 'Hits' => 'الزيارات', 'Suggested target' => 'الوجهة المقترحة', 'Save settings' => 'حفظ الإعدادات', 'Scan images' => 'فحص الصور', 'No 404 errors logged.' => 'لا توجد أخطاء 404 مسجلة.' ),
		'fr' => array( 'SEO Health' => 'Santé SEO', '404 log' => 'Journal 404', 'Redirects' => 'Redirections', 'Broken images' => 'Images cassées', 'Settings' => 'Réglages', 'Approve 301' => 'Approuver la 301', 'Ignore' => 'Ignorer', 'Delete' => 'Supprimer', 'Hits' => 'Visites', 'Suggested target' => 'Cible suggérée', 'Save settings' => 'Enregistrer', 'Scan images' => 'Analyser les images', 'No 404 errors logged.' => 'Aucune erreur 404 enregistrée.' ),
		'de' => array( 'SEO Health' => 'SEO-Zustand', '404 log' => '404-Protokoll', 'Redirects' => 'Weiterleitungen', 'Broken images' => 'Defekte Bilder', 'Settings' => 'Einstellungen', 'Approve 301' => '301 bestätigen', 'Ignore' => 'Ignorieren', 'Delete' => 'Löschen', 'Hits' => 'Aufrufe', 'Suggested target' => 'Vorgeschlagenes Ziel', 'Save settings' => 'Einstellungen speichern', 'Scan images' => 'Bilder prüfen', 'No 404 errors logged.' => 'Keine 404-Fehler protokolliert.' ),
	); }

	/* ---- path handling ---- */
	static function norm( $uri ) {
		$uri = (string) $uri; $p = wp_parse_url( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $uri ) ? $uri : 'http://x' . ( '/' === substr( $uri, 0, 1 ) ? '' : '/' ) . $uri, PHP_URL_PATH ); $p = is_string( $p ) ? rawurldecode( $p ) : '';
		$home = wp_parse_url( home_url(), PHP_URL_PATH ); if ( $home && '/' !== $home && 0 === strpos( $p, $home ) ) { $p = substr( $p, strlen( rtrim( $home, '/' ) ) ); }
		$p = '/' . trim( preg_replace( '#/+#', '/', $p ), '/' );
		$p = function_exists( 'mb_strtolower' ) ? mb_strtolower( $p ) : strtolower( $p );
		return mb_substr( $p, 0, 255 );
	}
	static function ignored( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ); $list = array_filter( array_map( 'trim', explode( ',', strtolower( self::s()['ignore_ext'] ) ) ) );
		return $ext && in_array( $ext, $list, true );
	}

	/* ---- redirect ---- */
	static function find_redirect( $path ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT id,to_url,code FROM ' . self::tred() . ' WHERE from_hash=%s', md5( $path ) ) ); }
	static function maybe_redirect() {
		if ( is_admin() || ! is_404() ) { return; }
		$path = self::norm( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore
		$r = self::find_redirect( $path ); if ( ! $r ) { return; }
		global $wpdb; $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tred() . ' SET hits=hits+1 WHERE id=%d', $r->id ) );
		wp_redirect( $r->to_url, (int) $r->code, 'NG SEO Health Monitor' ); // approved by an admin; target may be external
		exit;
	}

	/* ---- logging (no IP, no user agent) ---- */
	static function maybe_log() {
		$s = self::s(); if ( ! $s['log'] || is_admin() || ! is_404() || is_feed() || wp_doing_ajax() || defined( 'REST_REQUEST' ) ) { return; }
		$path = self::norm( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore
		if ( '/' === $path || self::ignored( $path ) ) { return; }
		self::log( $path, isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' ); // phpcs:ignore
	}
	static function log( $path, $referer = '' ) {
		global $wpdb; $s = self::s(); $now = current_time( 'mysql', true ); $h = md5( $path ); $t = self::t404();
		$host = ''; if ( $s['referrer'] && $referer ) { $host = (string) wp_parse_url( $referer, PHP_URL_HOST ); $host = substr( preg_replace( '/[^a-z0-9.\-]/i', '', $host ), 0, 120 ); if ( $host === wp_parse_url( home_url(), PHP_URL_HOST ) ) { $host = ''; } }
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $t SET hits=hits+1,last_seen=%s" . ( $host ? ',referrer_host=%s' : '' ) . ' WHERE path_hash=%s', $host ? array( $now, $host, $h ) : array( $now, $h ) ) );
		if ( $n ) { return; }
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
		if ( $count >= (int) $s['max_rows'] ) { $wpdb->query( "DELETE FROM $t WHERE status='open' ORDER BY hits ASC,last_seen ASC LIMIT 100" ); }
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (path_hash,path,hits,first_seen,last_seen,referrer_host) VALUES (%s,%s,1,%s,%s,%s)", $h, $path, $now, $now, $host ) );
	}
	static function purge() {
		global $wpdb; $d = max( 1, (int) self::s()['keep_days'] );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t404() . " WHERE status<>'open' OR last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - $d * DAY_IN_SECONDS ) ) );
	}

	/* ---- suggestions ---- */
	static function slug_of( $path ) { $seg = array_filter( explode( '/', $path ) ); $last = $seg ? end( $seg ) : ''; $last = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $last ); return trim( preg_replace( '/[^\p{L}\p{N}]+/u', '-', $last ), '-' ); }
	static function score( $a, $b ) {
		if ( '' === $a || '' === $b ) { return 0; }
		similar_text( $a, $b, $pct ); $ta = array_filter( explode( '-', $a ) ); $tb = array_filter( explode( '-', $b ) );
		$j = count( array_unique( array_merge( $ta, $tb ) ) ); $jac = $j ? count( array_intersect( $ta, $tb ) ) / $j * 100 : 0;
		return min( 100, round( max( $pct, ( $pct + $jac ) / 2 + ( $jac > 50 ? 10 : 0 ) ), 1 ) );
	}
	static function suggest( $path ) {
		global $wpdb; $slug = self::slug_of( $path ); if ( strlen( $slug ) < 3 ) { return null; }
		$rows = $wpdb->get_results( "SELECT ID,post_name FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND post_name<>'' ORDER BY ID DESC LIMIT 3000" );
		$best = null; $bs = 0;
		foreach ( $rows as $r ) { $sc = self::score( $slug, urldecode( $r->post_name ) ); if ( $sc > $bs ) { $bs = $sc; $best = $r; } }
		if ( ! $best || $bs < (float) self::s()['min_score'] ) { return null; }
		$url = get_permalink( $best->ID ); if ( self::norm( $url ) === $path ) { return null; }
		return array( 'post_id' => (int) $best->ID, 'url' => $url, 'score' => $bs );
	}

	/* ---- redirect creation ---- */
	static function valid_target( $url ) {
		$url = trim( (string) $url ); if ( '' === $url ) { return ''; }
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || ( '/' !== $url[1] && '\\' !== $url[1] ) ) ) { return esc_url_raw( home_url( $url ) ); }
		if ( 0 === strpos( $url, '//' ) ) { $url = 'https:' . $url; } return esc_url_raw( $url, array( 'http', 'https' ) );
	}
	static function add_redirect( $from_path, $to_url, $code = 301 ) {
		global $wpdb; $from = self::norm( $from_path ); $to = self::valid_target( $to_url );
		if ( ! $to || '/' === $from ) { return new WP_Error( 'bad', 'invalid' ); }
		if ( self::norm( $to ) === $from && wp_parse_url( $to, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) { return new WP_Error( 'loop', 'loop' ); }
		$code = in_array( (int) $code, array( 301, 302, 307, 308 ), true ) ? (int) $code : 301;
		$wpdb->query( $wpdb->prepare( 'REPLACE INTO ' . self::tred() . ' (from_hash,from_path,to_url,code,hits,created) VALUES (%s,%s,%s,%d,0,%s)', md5( $from ), $from, $to, $code, current_time( 'mysql', true ) ) );
		$wpdb->update( self::t404(), array( 'status' => 'redirected' ), array( 'path_hash' => md5( $from ) ) );
		return true;
	}

	/* ---- broken images (local files only) ---- */
	static function scan_images( $limit = 200 ) {
		$up = wp_get_upload_dir(); $base = trailingslashit( $up['basedir'] ); $burl = trailingslashit( $up['baseurl'] ); $out = array();
		$ids = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => (int) $limit, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true ) );
		foreach ( $ids as $id ) {
			$post = get_post( $id ); $srcs = array();
			if ( preg_match_all( '#<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']#i', $post->post_content, $m ) ) { $srcs = $m[1]; }
			$thumb = get_post_thumbnail_id( $id ); if ( $thumb ) { $f = get_attached_file( $thumb ); if ( $f && ! file_exists( $f ) ) { $out[] = array( 'post_id' => $id, 'src' => wp_get_attachment_url( $thumb ), 'why' => 'featured' ); } elseif ( ! $f ) { $out[] = array( 'post_id' => $id, 'src' => '#' . $thumb, 'why' => 'featured' ); } }
			foreach ( array_unique( $srcs ) as $src ) {
				$src = html_entity_decode( $src ); $s2 = preg_replace( '#^https?:#', '', $src ); $b2 = preg_replace( '#^https?:#', '', $burl );
				if ( 0 !== strpos( $s2, $b2 ) ) { continue; } // external or non-upload: not checked
				$rel = rawurldecode( strtok( substr( $s2, strlen( $b2 ) ), '?#' ) ); if ( false !== strpos( $rel, '..' ) ) { continue; }
				if ( ! file_exists( $base . $rel ) ) { $out[] = array( 'post_id' => $id, 'src' => $src, 'why' => 'missing' ); }
			}
		}
		update_option( self::IMG, array( 'time' => time(), 'scanned' => count( $ids ), 'items' => $out ), false );
		return $out;
	}

	/* ---- admin ---- */
	static function guard( $action ) { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No access', 403 ); } check_admin_referer( $action ); }
	static function back( $tab, $msg = '' ) { wp_safe_redirect( add_query_arg( array( 'page' => 'ngshm', 'tab' => $tab, 'msg' => $msg ), admin_url( 'tools.php' ) ) ); exit; }
	static function handle_approve() { self::guard( 'ngshm_approve' ); $id = (int) ( $_POST['id'] ?? 0 ); global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT path FROM ' . self::t404() . ' WHERE id=%d', $id ) );
		$to = isset( $_POST['to'] ) ? wp_unslash( $_POST['to'] ) : ''; if ( $row && $to ) { $r = self::add_redirect( $row->path, $to, 301 ); self::back( '404', is_wp_error( $r ) ? 'error' : 'approved' ); } self::back( '404', 'error' ); }
	static function handle_addredirect() { self::guard( 'ngshm_addredirect' ); $r = self::add_redirect( wp_unslash( $_POST['from'] ?? '' ), wp_unslash( $_POST['to'] ?? '' ), (int) ( $_POST['code'] ?? 301 ) ); self::back( 'redirects', is_wp_error( $r ) ? 'error' : 'approved' ); }
	static function handle_ignore() { self::guard( 'ngshm_ignore' ); global $wpdb; $wpdb->update( self::t404(), array( 'status' => 'ignored' ), array( 'id' => (int) ( $_POST['id'] ?? 0 ) ) ); self::back( '404' ); }
	static function handle_delete404() { self::guard( 'ngshm_delete404' ); global $wpdb; $wpdb->delete( self::t404(), array( 'id' => (int) ( $_POST['id'] ?? 0 ) ) ); self::back( '404' ); }
	static function handle_deleteredirect() { self::guard( 'ngshm_deleteredirect' ); global $wpdb; $wpdb->delete( self::tred(), array( 'id' => (int) ( $_POST['id'] ?? 0 ) ) ); self::back( 'redirects' ); }
	static function handle_scanimages() { self::guard( 'ngshm_scanimages' ); self::scan_images(); self::back( 'images' ); }
	static function handle_save() { self::guard( 'ngshm_save' ); $p = $_POST; $d = self::defaults();
		update_option( self::OPT, array( 'log' => empty( $p['log'] ) ? 0 : 1, 'referrer' => empty( $p['referrer'] ) ? 0 : 1, 'max_rows' => max( 100, min( 50000, (int) ( $p['max_rows'] ?? $d['max_rows'] ) ) ), 'keep_days' => max( 1, min( 730, (int) ( $p['keep_days'] ?? 90 ) ) ), 'min_score' => max( 30, min( 100, (int) ( $p['min_score'] ?? 60 ) ) ), 'ignore_ext' => preg_replace( '/[^a-z0-9,]/', '', strtolower( (string) ( $p['ignore_ext'] ?? $d['ignore_ext'] ) ) ) ) ); self::back( 'settings', 'saved' ); }
	static function btn( $action, $label, $fields = array(), $class = 'button' ) {
		$h = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( $action, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $k => $v ) { $h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">'; }
		return $h . '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form> ';
	}
	static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb; $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '404'; $tabs = array( '404' => __( '404 log', 'ng-shm' ), 'redirects' => __( 'Redirects', 'ng-shm' ), 'images' => __( 'Broken images', 'ng-shm' ), 'settings' => __( 'Settings', 'ng-shm' ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'SEO Health', 'ng-shm' ) . '</h1><h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $l ) { echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => 'ngshm', 'tab' => $k ), admin_url( 'tools.php' ) ) ) . '">' . esc_html( $l ) . '</a>'; }
		echo '</h2>';
		if ( '404' === $tab ) {
			$rows = $wpdb->get_results( "SELECT * FROM " . self::t404() . " WHERE status='open' ORDER BY hits DESC, last_seen DESC LIMIT 200" );
			if ( ! $rows ) { echo '<p>' . esc_html__( 'No 404 errors logged.', 'ng-shm' ) . '</p>'; } else {
				echo '<table class="widefat striped"><thead><tr><th>URL</th><th>' . esc_html__( 'Hits', 'ng-shm' ) . '</th><th>' . esc_html__( 'Suggested target', 'ng-shm' ) . '</th><th></th></tr></thead><tbody>';
				foreach ( $rows as $r ) { $sg = self::suggest( $r->path );
					echo '<tr><td><code>' . esc_html( $r->path ) . '</code>' . ( $r->referrer_host ? '<br><small>' . esc_html( $r->referrer_host ) . '</small>' : '' ) . '</td><td>' . (int) $r->hits . '</td><td>' . ( $sg ? esc_html( get_the_title( $sg['post_id'] ) ) . ' <small>(' . esc_html( $sg['score'] ) . '%)</small><br><code>' . esc_html( $sg['url'] ) . '</code>' : '&mdash;' ) . '</td><td>';
					if ( $sg ) { echo self::btn( 'ngshm_approve', __( 'Approve 301', 'ng-shm' ), array( 'id' => $r->id, 'to' => $sg['url'] ), 'button button-primary' ); }
					echo self::btn( 'ngshm_ignore', __( 'Ignore', 'ng-shm' ), array( 'id' => $r->id ) ) . self::btn( 'ngshm_delete404', __( 'Delete', 'ng-shm' ), array( 'id' => $r->id ) ) . '</td></tr>'; }
				echo '</tbody></table>'; }
		} elseif ( 'redirects' === $tab ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'ngshm_addredirect', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="ngshm_addredirect"><input name="from" placeholder="/old-path" class="regular-text"> &rarr; <input name="to" placeholder="/new-path or https://" class="regular-text"> <select name="code"><option>301</option><option>302</option><option>307</option><option>308</option></select> <button class="button button-primary">+</button></form><br>';
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::tred() . ' ORDER BY id DESC LIMIT 500' );
			echo '<table class="widefat striped"><thead><tr><th>From</th><th>To</th><th>Code</th><th>' . esc_html__( 'Hits', 'ng-shm' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) { echo '<tr><td><code>' . esc_html( $r->from_path ) . '</code></td><td>' . esc_html( $r->to_url ) . '</td><td>' . (int) $r->code . '</td><td>' . (int) $r->hits . '</td><td>' . self::btn( 'ngshm_deleteredirect', __( 'Delete', 'ng-shm' ), array( 'id' => $r->id ) ) . '</td></tr>'; }
			echo '</tbody></table>';
		} elseif ( 'images' === $tab ) {
			echo self::btn( 'ngshm_scanimages', __( 'Scan images', 'ng-shm' ), array(), 'button button-primary' ); $d = get_option( self::IMG );
			if ( $d ) { echo '<p>' . esc_html( sprintf( '%d posts, %d broken', $d['scanned'], count( $d['items'] ) ) ) . '</p><ul>'; foreach ( $d['items'] as $i ) { echo '<li><a href="' . esc_url( get_edit_post_link( $i['post_id'] ) ) . '">' . esc_html( get_the_title( $i['post_id'] ) ) . '</a>: <code>' . esc_html( $i['src'] ) . '</code></li>'; } echo '</ul>'; }
		} else {
			$s = self::s(); echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'ngshm_save', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="ngshm_save"><table class="form-table">';
			echo '<tr><th>Log 404</th><td><input type="checkbox" name="log" value="1"' . checked( $s['log'], 1, false ) . '></td></tr><tr><th>Referrer host</th><td><input type="checkbox" name="referrer" value="1"' . checked( $s['referrer'], 1, false ) . '></td></tr>';
			foreach ( array( 'max_rows', 'keep_days', 'min_score', 'ignore_ext' ) as $k ) { echo '<tr><th>' . esc_html( $k ) . '</th><td><input name="' . esc_attr( $k ) . '" value="' . esc_attr( $s[ $k ] ) . '"></td></tr>'; }
			echo '</table><button class="button button-primary">' . esc_html__( 'Save settings', 'ng-shm' ) . '</button></form>';
		}
		echo '<p><small>Product direction, scope and delivery: Nada Gamal</small></p></div>';
	}
}
register_activation_hook( __FILE__, array( 'NGSHM', 'install' ) );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( 'ngshm_purge' ); } );
NGSHM::init();
