<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ngshm_404' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ngshm_redirects' );
delete_option( 'ngshm_settings' ); delete_option( 'ngshm_images' );
