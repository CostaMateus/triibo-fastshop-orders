<?php
/**
 * Plugin Name:          #Triibo_Fastshop_Orders
 * Description:          Notifica a integração quando ocorre alteração no status de um pedido
 * Author:               Mateus Costa
 * Author URI:           https://costamateus.com.br/
 * Version:              2.0.1
 * Text Domain:          triibo-fastshop-orders
 * WC requires at least: 3.6
 * WC tested up to:      5.8
 *
 * @package Triibo_Fastshop_Orders
 */

if ( ! defined( "ABSPATH" ) ) exit;

include_once dirname( __FILE__ ) . "/includes/class-wc-triibo-fastshop-orders.php";

add_action( "woocommerce_order_status_changed", "triibo_split_order_fastshop", 7, 4 );
function triibo_split_order_fastshop( $order_id, $old_status, $new_status, $order )
{
	$items      = $order->get_items();
	$countItems = count( $items );
	$auxCount   = 0;
	$hasFS      = false;
	$onlyFS     = true;

	foreach ( $items as $item )
	{
		$product = $item->get_product();

		if ( class_exists( "WC_Subscriptions_Product" ) &&
			WC_Subscriptions_Product::is_subscription( $product ) )
		{
			$hasFS  = false;
			$onlyFS = false;
			break;
		}

		$hub     = $product->get_shipping_class();

		if ( strpos( $hub, "fast-shop" ) !== false )
		{
			$auxCount++;
			$hasFS = true;
		}
	}

	if ( $auxCount != $countItems ) $onlyFS = false;

	if ( $hasFS && $onlyFS )
	{
		if ( $old_status == "pending" )
		{
			// Remove the hook to prevent recursive callas.
			remove_action( "woocommerce_order_status_changed", "triibo_split_order_fastshop", 7 );

			// Split the order.
			$triibo = new WC_Triibo_Fastshop_Orders();
			$triibo->maybe_split_orders( $order_id, $order );

			// Add the hook back.
			add_action( "woocommerce_order_status_changed", "triibo_split_order_fastshop", 7, 4 );
		}
	}
}

add_action( "woocommerce_order_status_changed", "triibo_order_status_change", 9, 4 );
function triibo_order_status_change( $order_id, $old_status, $new_status, $order )
{
	$subOrder = empty( $order->get_meta( "has_sub_order" ) ) ? false : true;

	if ( !$subOrder )
	{
		$items = $order->get_items();
		$hasFS = false;

		foreach ( $items as $item )
		{
			$product = $item->get_product();
			$hub     = $product->get_shipping_class();

			if ( strpos( $hub, "fast-shop" ) !== false )
			{
				$hasFS = true;
				break;
			}
		}

		if ( $hasFS )
		{
			/**
			 * qlqr_status ( -aguardando ) --> processando
			 * pedido é CRIADO e na sequencia APROVADO na FS
			 */
			if ( $old_status != "on-hold" && $new_status == "processing" )
			{
				$api = new WC_Triibo_Fastshop_Orders( $order_id, "pending", "on-hold");
				$api->sendNotification(); // CRIA

				$api->set_process( "on-hold", "processing" );
				$api->sendNotification(); // APROVA
			}
			else
			{
				$api = new WC_Triibo_Fastshop_Orders( $order_id, $old_status, $new_status );
				$api->sendNotification();
			}
		}
	}
}

add_filter( "woocommerce_rest_suppress_image_upload_error", "triibo_wrsiu_error", 10, 4 );
function triibo_wrsiu_error( $false, $upload, $product_get_id, $images )
{
    return true;
}
