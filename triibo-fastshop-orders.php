<?php
/**
 * Plugin Name:          #Triibo_Fastshop_Orders
 * Description:          Notifica a integração quando ocorre alteração no status de um pedido
 * Author:               Mateus Costa
 * Version:              1.1.0
 * Text Domain:          triibo-fastshop-orders
 * WC requires at least: 3.6
 * WC tested up to:      5.8
 *
 * @package Triibo_Fastshop_Orders
 */

if ( ! defined( "ABSPATH" ) ) exit;

include_once dirname( __FILE__ ) . "/includes/class-wc-triibo-fastshop-orders.php";

add_action('woocommerce_order_status_changed','triibo_order_status_change', 10, 3);
function triibo_order_status_change( $order_id, $old_status, $new_status )
{
	$api = new WC_Triibo_Fastshop_Orders( $order_id, $old_status, $new_status );
	$api->sendNotification();
}