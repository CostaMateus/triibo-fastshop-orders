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
    private $_local_env     = true;

    private $_api_url_hml   = "http://php-hml.triibo.com.br";           // HOMOLOG
    private $_api_key_hml   = "fastshopappkey-triibo-AQPPTC";           // HOMOLOG
    private $_api_token_hml = "7472216240776562417070486d4c6170693139"; // HOMOLOG

    private $_api_url       = "http://php.triibo.com.br";               // PROD
    private $_api_key       = "fastshopappkey-triibo-AQPPTC";           // PROD
    private $_api_token     = "50083473200638420676844774754275516748"; // PROD

    private $header         = null;
    private $final_url      = null;

    private $order_id       = null;
    private $old_status     = null;
    private $new_status     = null;

	/**
	 * Initialize webservice.
	 *
	 * @param array $package Shipping package.
	 */
	public function __construct( $order_id, $old_status, $new_status )
    {
		$this->order_id   = $order_id;
		$this->old_status = $old_status;
		$this->new_status = $new_status;

        $this->get_headers();
        $this->process_order();
	}

    /**
     * Headers request
     *
     * @return array
     */
    private function get_headers()
    {
        $key   = ($this->_local_env) ? $this->_api_key_hml   : $this->_api_key;
        $token = ($this->_local_env) ? $this->_api_token_hml : $this->_api_token;

        $this->header = [
            "Accept: application/json",
            "Content-Type: application/json",
            "api-key: $key",
            "api-token: $token",
        ];
    }

	/**
	 * Process the order
	 *
	 * @return bool
	 */
	private function process_order()
    {
        // $this->log([
        //     "order_id"   => $this->order_id,
        //     "old_status" => $this->old_status,
        //     "new_status" => $this->new_status,
        // ]);

        $partial_url = ($this->_local_env) ? $this->_api_url_hml : $this->_api_url;
        $partial_url .= "/api/marketplace/wc";

        // pendente ----> aguardando
        // pedido é CRIADO na Fast Shop
        if ( $this->old_status == "pending" && $this->new_status == "on-hold" )
        {
            $partial_url    .= "/ordercreation/$this->order_id";
            $this->final_url = $partial_url;
        }

        // aguardando || malsucedido ----> cancelado
        // pedido é CANCELADO na Fast Shop
        else if ( ( $this->old_status == "on-hold"    || $this->old_status == "failed"   ) && $this->new_status == "cancelled" )
        {
            $partial_url    .= "/ordercancelation/$this->order_id";
            $this->final_url = $partial_url;
        }

        // aguardando || malsucedido ----> processando
        // pedido é APROVADO na Fast Shop
        else if ( ( $this->old_status == "on-hold"    || $this->old_status == "failed"   ) && $this->new_status == "processing" )
        {
            $partial_url    .= "/orderapproved/$this->order_id";
            $this->final_url = $partial_url;
        }

        // processando || concluido ----> cancelado
        // pedido processando ou concluído
        else if ( ( $this->old_status == "processing" || $this->old_status == "completed" ) && $this->new_status == "cancelled" )
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
    private function log( $data = ["null"], $type = "info" )
    {
        $logger  = wc_get_logger();
        $context = [ "source" => "fastshop-integration" ];
        $message = json_encode( $data );

        $logger->log( $type, $message, $context );
    }

    public function sendNotification()
    {
        if (!$this->final_url) return;

        $ch  = curl_init();

		curl_setopt_array( $ch, [
			CURLOPT_URL            => $this->final_url,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_ENCODING       => "",
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
			// CURLOPT_SSL_VERIFYHOST => FALSE,
			// CURLOPT_SSL_VERIFYPEER => FALSE,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => "POST",
			CURLOPT_HTTPHEADER     => $this->header,
            CURLOPT_POSTFIELDS     => "{}"
        ]);

        $response = curl_exec( $ch );
        curl_close( $ch );

        $response = json_decode( $response, TRUE );

        $this->log($response);

        return $response;
    }
}
