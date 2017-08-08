<?php
/**
 * Adds the custom fields to the registration form and profile editor
 *
 * @package svbk-rcp-fattureincloud
 * @author Brando Meniconi <b.meniconi@silverbackstudio.it>
 */

/*
Plugin Name: Restrict Content Pro - Fatture In Cloud
Description: Integrates RCP with FattureInCloud
Author: Silverback Studio
Version: 1.0
Author URI: http://www.silverbackstudio.it/
Text Domain: svbk-rcp-fattureincloud
*/


use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Svbk\FattureInCloud;
use Svbk\FattureInCloud\Struct\DocNuovoArticolo as Articolo;
use Svbk\FattureInCloud\Struct\DocNuovoRequest as Fattura;
use Svbk\FattureInCloud\Struct\DocNuovoPagamento as Pagamento;

/**
 * Loads textdomain and main initializes main class
 *
 * @return void
 */
function svbk_rcp_fattureincloud_init() {
	load_plugin_textdomain( 'svbk-rcp-fattureincloud', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'plugins_loaded', 'svbk_rcp_fattureincloud_init' );

function svbk_rcp_fattureincloud_client() {

	$client = new FattureInCloud\Client( env( 'FATTUREINCLOUD_API_UID' ) ,env( 'FATTUREINCLOUD_API_KEY' ) );

	$log = new Logger( 'fattureincloud' );
	$log->pushHandler( new StreamHandler( WP_CONTENT_DIR . '/fattureincloud.log', Logger::INFO ) );
	$client->setLogger( $log );

	return $client;
}

function svbk_rcp_fattureincloud_countries( $countries ) {

	$client = svbk_rcp_fattureincloud_client();
	$cache_key = 'svbk_rcp_fattureincloud_countries';
	$countries = get_transient( $cache_key );

	if ( false === $countries ) {
		$response = $client->getInfoList( array( 'lista_paesi' ) );

		if ( $response->lista_paesi ) {
			$countries = $response->lista_paesi;
			set_transient( $cache_key, $countries, 2 * DAY_IN_SECONDS );
		}
	}

	return $countries;
}

add_filter( 'svbk_rcp_company_details_countries', 'svbk_rcp_fattureincloud_countries' );

/**
 * Trigger invoice download
 *
 * @uses rcp_generate_invoice()
 *
 * @return void
 */
function svbk_rcp_trigger_invoice_download() {

	if ( ! isset( $_GET['rcp-action'] ) || 'download_invoice' != $_GET['rcp-action'] ) {
		return;
	}

	if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'rcp_download_invoice_nonce' ) ) {
		return;
	}

	$payment_id = absint( $_GET['payment_id'] );

	if ( empty( $payment_id ) ) {
		return;
	}

	$payments_db  = new RCP_Payments;
	$payment      = $payments_db->get_payment( $payment_id );

	if ( ! $payment ) {
		wp_die( esc_html__( 'This payment record does not exist', 'rcp' ) );
	}

	if ( get_current_user_id() != $payment->user_id && ! current_user_can( 'rcp_manage_payments' ) ) {
		wp_die( esc_html__( 'You do not have permission to download this invoice', 'rcp' ) );
	}

	$rcp_payment = $payment;
	$rcp_member = new RCP_Member( $payment->user_id );

	$invoice_url = $payments_db->get_meta( $payment_id, 'fattureincloud_invoice_url', true );

	if ( ! $invoice_url ) {
		wp_die( esc_html__( 'Invoice not yet available, please contact and administrative to get more info', 'svbk-rcp-fattureincloud' ) );
	}

	wp_redirect( $invoice_url );
	die();

}
add_action( 'init', 'svbk_rcp_trigger_invoice_download', 9 );


function svbk_rcp_generate_invoice( $payment_id ) {

	$payments_db  = new RCP_Payments;
	$rcp_payment  = $payments_db->get_payment( $payment_id );

	$rcp_member = new RCP_Member( $rcp_payment->user_id );

	$id_fattura = $payments_db->get_meta( $payment_id, 'fattureincloud_invoice_id', true );
	$invoiceService = svbk_rcp_fattureincloud_client();

	if ( ! $id_fattura ) {

		$invoiceArticle = new Articolo(
			array(
				'nome' => $rcp_payment->subscription,
				'prezzo_lordo' => $rcp_payment->amount,
				'cod_iva' => 0,
			)
		);

		$data_pagamento = FattureInCloud\Date::createFromFormat( 'Y-m-d H:i:s', $rcp_payment->date );

		$invoicePayment = new Pagamento(
			array(
				'data_scadenza' => $data_pagamento,
				'importo' => 'auto',
				'metodo' => env( 'FATTUREINCLOUD_STRIPE_WALLET' ),
				'data_saldo' => $data_pagamento,
			)
		);

		$newInvoice = new Fattura( array(
			'nome' => $rcp_member->company_name ?: ($rcp_member->first_name . ' ' . $rcp_member->last_name),
			'indirizzo_via' => $rcp_member->billing_address,
			'indirizzo_cap' => $rcp_member->billing_postal_code,
			'indirizzo_citta' => $rcp_member->biling_city,
			'indirizzo_provincia' => $rcp_member->billing_state,
			'paese' => $rcp_member->billing_country,
			'lista_articoli' => array( $invoiceArticle ),
			'lista_pagamenti' => array( $invoicePayment ),
			'prezzi_ivati' => true,
		));

		$result = $invoiceService->createDoc( FattureInCloud\Client::TYPE_FATTURA, $newInvoice );

		if ( $result ) {
			$id_fattura = $result->new_id;
			$payments_db->add_meta( $payment_id, 'fattureincloud_invoice_id', $id_fattura, true );
		}
	}// End if().

	$url_fattura = $payments_db->get_meta( $payment_id, 'fattureincloud_invoice_url', true );

	if ( $id_fattura && ! $url_fattura ) {

		$dettagliRequest = new FattureInCloud\Struct\DocDettagliRequest(
			array(
				'id' => $id_fattura,
			)
		);

		$result = $invoiceService->getDettagliDoc( FattureInCloud\Client::TYPE_FATTURA, $dettagliRequest );

		if ( $result ) {
			$url_fattura = $result->dettagli_documento->link_doc;
			$payments_db->add_meta( $payment_id, 'fattureincloud_invoice_url', $url_fattura, true );
		}
	}

	return $url_fattura;

}

add_action( 'rcp_insert_payment', 'svbk_rcp_generate_invoice', 10, 3 );


function svbk_rcp_fattureincloud_settings( $rcp_options ) {
	?>
		<h3><?php esc_html_e( 'FattureInCloud', 'svbk-rcp-fattureincloud' ) ?></h3>
		<table class="form-table">
			<tr valign="top">
				<th>
					<label for="rcp_settings[fattureincloud_api_uid]"><?php esc_html_e( 'API UID', 'svbk-rcp-fattureincloud' ); ?></label>
				</th>
				<td>
					<input class="regular-text" id="rcp_settings[fattureincloud_api_uid]" style="width: 300px;" name="rcp_settings[fattureincloud_api_uid]" value="<?php if ( isset( $rcp_options['fattureincloud_api_uid'] ) ) { echo esc_attr( $rcp_options['fattureincloud_api_uid'] ); } ?>"/>
					<p class="description"><?php esc_html_e( 'Enter the FattureInCloud API UID.', 'svbk-rcp-fattureincloud' ); ?></p>
				</td>
			</tr>
			<tr valign="top">
				<th>
					<label for="rcp_settings[fattureincloud_api_key]"><?php esc_html_e( 'API KEY', 'svbk-rcp-fattureincloud' ); ?></label>
				</th>
				<td>
					<input class="regular-text" id="rcp_settings[fattureincloud_api_key]" style="width: 300px;" name="rcp_settings[fattureincloud_api_key]" value="<?php if ( isset( $rcp_options['fattureincloud_api_key'] ) ) { echo esc_attr( $rcp_options['fattureincloud_api_key'] ); } ?>"/>
					<p class="description"><?php esc_html_e( 'Enter the FattureInCloud API KEY.', 'svbk-rcp-fattureincloud' ); ?></p>
				</td>
			</tr>
			<tr valign="top">
				<th>
					<label for="rcp_settings[fattureincloud_wallet]"><?php esc_html_e( 'Wallet', 'svbk-rcp-fattureincloud' ); ?></label>
				</th>
				<td>
					<input class="regular-text" id="rcp_settings[fattureincloud_wallet]" style="width: 300px;" name="rcp_settings[fattureincloud_wallet]" value="<?php if ( isset( $rcp_options['fattureincloud_wallet'] ) ) { echo esc_attr( $rcp_options['fattureincloud_wallet'] ); } ?>"/>
					<p class="description"><?php esc_html_e( 'Enter the FattureInCloud default wallet', 'svbk-rcp-fattureincloud' ); ?></p>
				</td>
			</tr>						
		</table>
<?php }

add_action( 'rcp_invoice_settings', 'svbk_rcp_fattureincloud_settings' );
