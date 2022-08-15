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
	public function __construct( $order_id, $old_status, $new_status )
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
        // qlqr_status ( -processando -concluido )  --> aguardando   ==  cria FS
        // pendente                                 --> malsucedido  ==  cria FS
        // aguardando || malsucedido                --> processando  ==  aprova FS
        // qlqr_status ( -pendente )                --> cancelado    ==  cancelo FS
        // qlqr_status ( -aguardando -malsucedido ) --> processando  ==  cria e aprova FS

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
         * pendente --> malsucedido
         * pedido é CRIADO na FS
         */
        elseif ( $this->old_status == "pending" && $this->new_status == "failed" )
        {
            $partial_url    .= "/ordercreation/$this->order_id";
            $this->final_url = $partial_url;
        }

        /**
         * aguardando || malsucedido --> processando
         * pedido é APROVADO na FS
         */
        elseif ( in_array( $this->old_status, [ "on-hold", "failed" ] ) && $this->new_status == "processing" )
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
     * @param array|null $data
     * @param string $type
     * @return void
     */
    private function log( $data = [ "null" ], $type = "info" )
    {
        $logger  = wc_get_logger();
        $context = [ "source" => "fastshop-integration" ];
        $message = json_encode( $data );

        $logger->log( $type, $message, $context );
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

        $this->log( $response );

        return $response;
    }
}
