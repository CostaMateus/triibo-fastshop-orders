<?php
/**
 * Fastshop
 *
 * @package Triibo_Fastshop_Orders/Classes
 * @since   1.0.0
 * @version 1.0.0
 */

if ( ! defined( "ABSPATH" ) ) exit;


/**
 * Plugins main class.
 */
class WC_Triibo_Fastshop_Orders
{
    const _api_url_hml   = "http://php-hml.triibo.com.br";           // HOMOLOG
    const _api_key_hml   = "fastshopappkey-triibo-AQPPTC";           // HOMOLOG
    const _api_token_hml = "7472216240776562417070486d4c6170693139"; // HOMOLOG

    const _api_url       = "http://php.triibo.com.br";               // PROD
    const _api_key       = "fastshopappkey-triibo-AQPPTC";           // PROD
    const _api_token     = "50083473200638420676844774754275516748"; // PROD

    private $header      = null;
    private $final_url   = null;

    private $order_id    = null;
    private $old_status  = null;
    private $new_status  = null;

    private $url         = null;

	/**
	 * Initialize webservice.
	 *
	 * @param array $package Shipping package.
	 */
	public function __construct( $order_id = null, $old_status = null, $new_status = null )
    {
        if ( $order_id && $old_status && $new_status )
        {
            $_local_env       = ( strpos( home_url(), "dev-" ) !== FALSE ) ? true : false;

            $key              = $_local_env ? self::_api_key_hml   : self::_api_key;
            $token            = $_local_env ? self::_api_token_hml : self::_api_token;
            $this->url        = $_local_env ? self::_api_url_hml   : self::_api_url;

            $this->order_id   = $order_id;
            $this->old_status = $old_status;
            $this->new_status = $new_status;

            $this->header     = [
                "Accept: application/json",
                "Content-Type: application/json",
                "api-key: $key",
                "api-token: $token",
            ];

            $this->process_order();
        }
	}

    public function set_process( $old_status, $new_status )
    {
		$this->old_status = $old_status;
		$this->new_status = $new_status;

        $this->process_order();
    }

	/**
	 * Process the order
	 *
	 * @return bool
	 */
	private function process_order()
    {
        // // qlqr_status ( -processando -concluido )  --> aguardando   ==  cria FS
        // // pendente                                 --> malsucedido  ==  cria FS
        // // aguardando || malsucedido                --> processando  ==  aprova FS
        // // qlqr_status ( -pendente )                --> cancelado    ==  cancelo FS
        // // qlqr_status ( -aguardando -malsucedido ) --> processando  ==  cria e aprova FS

        // qlqr_status ( -processando -concluido )  --> aguardando   ==  cria FS
        // aguardando                               --> processando  ==  aprova FS
        // qlqr_status ( -pendente )                --> cancelado    ==  cancelo FS
        // qlqr_status ( -aguardando )              --> processando  ==  cria e aprova FS

        $partial_url = $this->url . "/api/marketplace/wc";

        /**
         * qlqr_status ( -processando -concluido ) --> aguardando
         * pedido é CRIADO na FS
         */
        if ( !in_array( $this->old_status, [ "processing", "completed" ] ) && $this->new_status == "on-hold" )
        {
            $partial_url    .= "/ordercreation/$this->order_id";
            $this->final_url = $partial_url;
        }

        /**
         * aguardando --> processando
         * pedido é APROVADO na FS
         */
        elseif ( $this->old_status == "on-hold" && $this->new_status == "processing" )
        {
            $partial_url    .= "/orderapproved/$this->order_id";
            $this->final_url = $partial_url;
        }

        /**
         * qlqr_status ( -pendente ) --> cancelado
         * pedido é CANCELADO na FS
         */
        elseif ( $this->old_status != "pending" && $this->new_status == "cancelled" )
        {
            $partial_url    .= "/ordercancelation/$this->order_id";
            $this->final_url = $partial_url;
        }
    }

    /**
     * Register log
     *
     * @param string|null $data
     * @param string $type
     * @return void
     */
    public function log( $data = "", $type = "info" )
    {
        $log = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();
        $log->add( "fastshop_orders", $data );
    }

    public function sendNotification()
    {
        if ( !$this->final_url ) return;

        $ch  = curl_init();

		curl_setopt_array( $ch, [
			CURLOPT_URL            => $this->final_url,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_ENCODING       => "",
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => "POST",
			CURLOPT_HTTPHEADER     => $this->header,
            CURLOPT_POSTFIELDS     => "{}"
        ]);

        $response = curl_exec( $ch );
        curl_close( $ch );

        $response = json_decode( $response, TRUE );

        $this->log( json_encode( $response ) );

        return $response;
    }

    public function maybe_split_orders( $parent_order_id, $parent_order )
    {
        $this->log( sprintf( "TRIIBO: New Order #%d created. Init sub order.", $parent_order_id ) );

        if ( $parent_order->get_meta( "has_sub_order" ) == true )
        {
            $args = [
                "post_parent" => $parent_order_id,
                "post_type"   => "shop_order",
                "numberposts" => -1,
                "post_status" => "any",
            ];

            $child_orders = get_children( $args );

            foreach ( $child_orders as $child ) wp_delete_post( $child->ID, true );
        }

        $hubs        = [];
        $seller_id   = null;
        $order_items = $parent_order->get_items();

        foreach ( $order_items as $item )
        {
            $product = $item->get_product();
            $hub     = $product->get_shipping_class();

            if ( strpos( $hub, "fast-shop-" ) !== false )
            {
                $seller_id      = get_post_field( "post_author", $item[ "product_id" ] );
                $hubs[ $hub ][] = $item;
            }
        }

        if ( count( $hubs ) <= 1 )
        {
            $this->log( "TRIIBO: 1 hub only, skipping sub order" );
            return;
        }

        $parent_order->update_meta_data( "has_sub_order", true );
        $parent_order->save();

        $this->log( sprintf( "TRIIBO: Got %s hubs, starting sub order", count( $hubs ) ) );

        foreach ( $hubs as $hub => $hub_products )
        {
            $this->create_sub_order( $parent_order, $seller_id, $hub, $hub_products );
        }
    }

    private function create_sub_order( $parent_order, $seller_id, $hub, $hub_products )
    {
        $this->log( "TRIIBO: Creating sub order for hub: " . $hub );

        $bill_ship = [
            "billing_country",
            "billing_first_name",
            "billing_last_name",
            "billing_company",
            "billing_address_1",
            "billing_address_2",
            "billing_city",
            "billing_state",
            "billing_postcode",
            "billing_email",
            "billing_phone",
            "shipping_country",
            "shipping_first_name",
            "shipping_last_name",
            "shipping_company",
            "shipping_address_1",
            "shipping_address_2",
            "shipping_city",
            "shipping_state",
            "shipping_postcode",
        ];

        try
        {
            $order = new \WC_Order();

            // save billing and shipping address
            foreach ( $bill_ship as $key )
                if ( is_callable( [ $order, "set_{$key}" ] ) )
                    $order->{ "set_{$key}" }( $parent_order->{"get_{$key}"}() );

            // now insert line items
            $this->create_line_items( $order, $hub_products );

            // do shipping
            $this->create_shipping( $order, $parent_order, $hub_products );

            // do tax
            $this->create_taxes( $order, $parent_order, $hub_products );

            // add coupons if any
            $this->create_coupons( $order, $parent_order, $hub_products );

            // save other details
            $order->set_created_via( "triibo-fastshop-orders" );
            $order->set_cart_hash( $parent_order->get_cart_hash() );
            $order->set_customer_id( $parent_order->get_customer_id() );
            $order->set_currency( $parent_order->get_currency() );
            $order->set_prices_include_tax( $parent_order->get_prices_include_tax() );
            $order->set_customer_ip_address( $parent_order->get_customer_ip_address() );
            $order->set_customer_user_agent( $parent_order->get_customer_user_agent() );
            $order->set_customer_note( $parent_order->get_customer_note() );
            $order->set_payment_method( $parent_order->get_payment_method() );
            $order->set_payment_method_title( $parent_order->get_payment_method_title() );
            $order->update_meta_data( "_dokan_vendor_id", $seller_id );

            // finally, let the order re-calculate itself and save
            $order->calculate_totals();

            $order->set_status( $parent_order->get_status() );
            $order->set_parent_id( $parent_order->get_id() );

            $order_id = $order->save();

            $this->log( "TRIIBO: Created sub order : #" . $order_id );

            // update total_sales count for sub-order
            wc_update_total_sales_counts( $order_id );
        }
        catch ( Exception $e )
        {
            return new WP_Error( "dokan-suborder-error", $e->getMessage() );
        }
    }

    private function create_line_items( $order, $products )
    {
        foreach ( $products as $item )
        {
            $product_item = new \WC_Order_Item_Product();

            $product_item->set_name         ( $item->get_name()         );
            $product_item->set_product_id   ( $item->get_product_id()   );
            $product_item->set_variation_id ( $item->get_variation_id() );
            $product_item->set_quantity     ( $item->get_quantity()     );
            $product_item->set_tax_class    ( $item->get_tax_class()    );
            $product_item->set_subtotal     ( $item->get_subtotal()     );
            $product_item->set_subtotal_tax ( $item->get_subtotal_tax() );
            $product_item->set_total_tax    ( $item->get_total_tax()    );
            $product_item->set_total        ( $item->get_total()        );
            $product_item->set_taxes        ( $item->get_taxes()        );

            $metadata     = $item->get_meta_data();

            if ( $metadata )
                foreach ( $metadata as $meta )
                    $product_item->add_meta_data( $meta->key, $meta->value );

            $order->add_item( $product_item );
        }

        $order->save();
    }

    private function create_shipping( $order, $parent_order, $products )
    {
        $shipping_methods         = $parent_order->get_shipping_methods();

        if ( !$shipping_methods )
        {
            $this->log( "TRIIBO: No shipping method found : Aborting : #" . $order->get_id() );
            return;
        }

        foreach ( $shipping_methods as $key => $shipping_method )
        {
            if ( is_a( $shipping_method, "WC_Order_Item_Shipping" ) )
            {
                $total    = 0;

                $item     = new \WC_Order_Item_Shipping();
                $metadata = $shipping_method->get_meta_data();

                if ( $metadata )
                {
                    foreach ( $metadata as $meta )
                    {
                        if ( $meta->key == "price" )
                        {
                            $data     = $meta->get_data();
                            $new_meta = [ "value" => [] ];

                            foreach ( $products as $product )
                            {
                                $product_id = $product->get_product_id();

                                if ( isset( $data[ "value" ][ $product_id ] ) )
                                {
                                    $value  = $data[ "value" ][ $product_id ];
                                    $total += $value;

                                    $new_meta[ "value" ][ $product_id ] = $value;
                                }
                            }

                            $item->add_meta_data( $meta->key, $new_meta );
                        }
                        else
                        {
                            $item->add_meta_data( $meta->key, $meta->value );
                        }
                    }
                }

                $item->set_props( [
                    "method_title" => $shipping_method->get_name(),
                    "method_id"    => $shipping_method->get_method_id(),
                    "taxes"        => $shipping_method->get_taxes(),
                    "total"        => $total,
                ] );

                $order->add_item( $item );
                $order->set_shipping_total( $shipping_method->get_total() );
                $order->save();
            }
        }
    }

    private function create_taxes( $order, $parent_order, $products )
    {
        $shipping  = $order->get_items( "shipping" );
        $tax_total = 0;

        foreach ( $products as $item )
            $tax_total += $item->get_total_tax();

        foreach ( $parent_order->get_taxes() as $tax )
        {
            $seller_shipping = reset( $shipping );

            $item = new \WC_Order_Item_Tax();
            $item->set_props( [
                "rate_id"            => $tax->get_rate_id(),
                "label"              => $tax->get_label(),
                "compound"           => $tax->get_compound(),
                "rate_code"          => \WC_Tax::get_rate_code( $tax->get_rate_id() ),
                "tax_total"          => $tax_total,
                "shipping_tax_total" => is_bool( $seller_shipping ) ? "" : $seller_shipping->get_total_tax(),
            ] );

            $order->add_item( $item );
        }

        $order->save();
    }

    private function create_coupons( $order, $parent_order, $products )
    {
        $used_coupons = $parent_order->get_items( "coupon" );
        $product_ids  = array_map(
            function( $item ) {
                return $item->get_product_id();
            }, $products
        );

        if ( !$used_coupons ) return;

        $seller_id = dokan_get_seller_id_by_order( $order->get_id() );

        if ( !$seller_id ) return;

        foreach ( $used_coupons as $item )
        {
            $coupon = new \WC_Coupon( $item->get_code() );

            if (
                $coupon &&
                !is_wp_error( $coupon ) &&
                ( array_intersect( $product_ids, $coupon->get_product_ids() ) ||
                    apply_filters( "dokan_is_order_have_admin_coupon", false, $coupon, [ $seller_id ], $product_ids )
                )
            ) {
                $new_item = new \WC_Order_Item_Coupon();
                $new_item->set_props( [
                    "code"         => $item->get_code(),
                    "discount"     => $item->get_discount(),
                    "discount_tax" => $item->get_discount_tax(),
                ] );

                $new_item->add_meta_data( "coupon_data", $coupon->get_data() );

                $order->add_item( $new_item );
            }
        }

        $order->save();
    }
}
