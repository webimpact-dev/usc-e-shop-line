<?php
/*
Plugin Name: LINE Pay for Welcart
Plugin URI: https://github.com/webimpact-dev/usc-e-shop-line
Description: LINE Payを決済方法に追加するプラグインです。
Author: WEBIMPACT
Version: 1.0.0
Author URI: https://www.webimpact.co.jp/
*/
 
define('USCES_LINE_WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('USCES_LINE_WP_PLUGIN_DIR', USCES_LINE_WP_CONTENT_DIR . '/plugins');

define('USCES_LINE_PLUGIN_DIR', USCES_LINE_WP_PLUGIN_DIR . '/' . plugin_basename(dirname(__FILE__)));

require_once(USCES_LINE_PLUGIN_DIR."/classes/PaymentLinePay.class.php");

add_action( 'plugins_loaded', 'usces_line_instance_settlement');

function usces_line_instance_settlement(){
	$linepay = LINEPAY_SETTLEMENT::get_instance();
}
