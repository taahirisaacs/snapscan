<?php

add_filter( 'woocommerce_payment_gateways', 'add_snapscan_class' );

function add_snapscan_class( $gateways ) {
    $gateways[] = 'WC_Ecentric_Snapscan';
    return $gateways;
}

add_action( 'plugins_loaded', 'init_snapscan_class' );

function prefix_register_my_rest_routes() {
    $controller = new WC_Ecentric_Snapscan();
    $controller->register_routes();
}

add_action( 'rest_api_init', 'prefix_register_my_rest_routes');


function init_snapscan_class()
{
    class WC_Ecentric_Snapscan extends WC_Payment_Gateway
    {

        public function __construct()
        {
            $this->id = 'snapscan';
            $this->icon = $this->plugin_url() . '/assets/snapscan_images/SnapScan_logo_blue_v1.svg';;
            // URL of the icon that will be displayed on checkout page near your gateway name
            $this->method_title = 'SnapScan App Payments';
            $this->method_description = 'Accept payments from the SnapScan app though Scan-to-Pay on desktop or Pay Links on mobile sites.';

            $this->supports = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->api_endpoint = 'https://pos.snapscan.io/merchant/api/v1/payments';

            $this->title = $this->get_option('header');
            $this->description = $this->get_option('desc');
            $this->enabled = $this->get_option('on');
            $this->merchant = $this->get_option('merchant');
            $this->snap_api = $this->get_option('snap_api');
            $this->webhook_auth = $this->get_option('webhook_auth');

            add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ) );

            add_action('woocommerce_receipt_snapscan', array($this, 'checkout'));

        }

        public function register_routes()
        {
            register_rest_route('snap', '/payment-complete', array(
                'methods' => 'POST',
                'callback' => array($this, 'snap_request'),
                'permission_callback' => '__return_true',
            ));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'on' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable SnapScan',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'header' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'default' => 'SnapScan'
                ),
                'desc' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay using the SnapScan app.',
                ),
                'merchant' => array(
                    'title' => 'Merchant ID',
                    'type' => 'text'
                ),
                'snap_api' => array(
                    'title' => 'API Key',
                    'type' => 'text'
                ),
                'webhook_auth' => array(
                    'title' => 'Webhook Auth Key',
                    'type' => 'text'
                ),
            );
        }

        function plugin_url()
        {
            if (isset($this->plugin_url)) {
                return $this->plugin_url;
            }

            if (is_ssl()) {
                return $this->plugin_url = str_replace('http://', 'https://', WP_PLUGIN_URL) . "/" . plugin_basename(dirname(dirname(__FILE__)));
            } else {
                return $this->plugin_url = WP_PLUGIN_URL . "/" . plugin_basename(dirname(dirname(__FILE__)));
            }
        }

        function snap_request()
        {
            $entityBody = file_get_contents('php://input');
            $header = null;
            foreach (getallheaders() as $name => $value) {
                if ($name == 'Authorization') {
                    $header = $value;
                }
            }
            $params = json_decode(stripslashes(stripslashes($_POST["payload"])));

            $signature = hash_hmac('sha256', $entityBody, $this->settings['webhook_auth']);
            $auth = "SnapScan signature=$signature";

            if (hash_equals($header, $auth)) {
                $order = new WC_Order((int)$params->merchantReference);
                $status = $params->status;
                $total = $params->totalAmount;
                $required = $params->requiredAmount;

                if ($required > $total) {
                    return array('error' => 'Required amount not met.');
                }

                if ($status == 'completed' || $status == 'pending') {
                    $order->payment_complete();
                }
                return array('success');
            }else{
                $check_order_url = $this->api_endpoint;

                if (strrpos($check_order_url, '?') === false) {
                    $check_order_url .= '?merchantReference=' . $params->merchantReference;
                } else {
                    $check_order_url .= '&merchantReference=' . $params->merchantReference;
                }

                $header = 'Basic '. base64_encode($this->settings['snap_api'] . ':' . '');

                $response = wp_remote_get($check_order_url, array(
                    'headers' => array(
                        'Authorization' => $header
                    )
                ));
                $body = wp_remote_retrieve_body( $response );
                $body = json_decode($body)[0];

                $order = new WC_Order((int) $body->merchantReference);
                if ($body->status == "completed" || $body->status === "pending"){
                    $order->payment_complete();
                }
                return array('success');
            }
        }

        function checkout($order_id)
        {
            $order = new WC_Order($order_id);

            $order_received_url = $order->get_checkout_order_received_url();
            $order_checkout_payment_url = $order->get_checkout_payment_url(true);


            $total_in_cents = round($order->get_total() * 100);
            $snapcode = $this->settings['merchant'];
            $qr_url = 'https://pos.snapscan.io/qr/' . $snapcode . '?id=' . $order_id . '&strict=true&amount=' . $total_in_cents;
            $qr_image_url = 'https://pos.snapscan.io/qr/' . $snapcode . '.png?id=' . $order_id . '&strict=true&amount=' . $total_in_cents . '&snap_code_size=180';

            print '
		<div class="snapscan-wrapper">
			<style type="text/css">
				#snapscan-widget {
				  width: 208px;
				  padding: 30px 20px;
				  background-color: #ffffff;
				  box-sizing: content-box;
				}
				#snapscan-widget div, #snapscan-widget img, #snapscan-widget a {
				  line-height: 1em;
				}
				#snapscan-widget .snap-code-contaner {
				  background-color: #ffffff;
				  padding: 24px 24px 12px;
				  box-sizing: content-box;
				  border: 1px solid #4A90E2;
				  border-radius: 24px 24px 0 0;
				}
				#snapscan-widget .download-links {
				  display: inline-flex;
				  margin-top: 16px;
				}
			    #snapscan-widget .snap-code-contaner .scan-header {
				  width: 160px;
				  margin-top: 12px;
				  margin-bottom: 12px;
				  text-align: center;
				  font-size: 16px;
				  color: #263943;
				}
				#snapscan-widget .scan-footer {
				  margin-bottom: 16px;
				  padding: 12px 24px;
				  box-sizing: content-box;
				  background-color: #4A90E2;
				  border-radius: 0 0 24px 24px;
				}
				#snapscan-widget b{
				    text-align: center;
				}
				#snapscan-widget .download-text{
				    font-size: 12px;
				    color: #263943;
				    padding: 0;
				    margin: 0;
				}
				#snapscan-widget .pay-link {
				  text-decoration:none;
				  border:none;
				  display: none;
				  box-sizing: content-box;
				  margin: 0;
				  padding: 0;
				}
				#snapscan-widget .download-links a{
				    max-width: 33%;
				 }
				 #snapscan-widget .download-links .link{
				    margin-right: 14px;
				 }
				#snapscan-widget img {
				  border:none;
				  margin-bottom: 4px;
				  padding: 0;
				  background:transparent;
				  box-sizing: content-box;
				  box-shadow: none;
				  display:block;
				}
				#snapscan-widget .pay-link .tap-to-pay{
				    display: inline-flex;
				    background-color: #4A90E2;
				    border: 1px solid #4A90E2;
				    border-radius: 24px;
				    margin-bottom: 10px;
				    margin-top: 8px;
				    padding: 4px;
				}
				#snapscan-widget .pay-link .tap-to-pay .snap-logo{
				    margin-right: 8px;
				}
				#snapscan-widget .pay-link .tap-to-pay .tap-text{
				    font-size: 17px;
				    font-weight: bold;
				    color: #ffffff;
				    margin-right: 8px;
				    margin-top: 8px;
				    margin-bottom: 8px;
				}
				#snapscan-widget .scan-footer .logo{
				    margin: auto;
				}
				@media screen and (max-device-width: 667px){
				  #snapscan-widget .snap-code-contaner  {
					display: none;
				  }
				  #snapscan-widget .scan-text {
					display: none;
				  }
				  .card-link {
				    display: none;
				  }
				  #snapscan-widget .pay-link {
				  text-decoration:none;
				  border:none;
				  display: block;
				  box-sizing: content-box;
				  margin: 0;
				  padding: 0;
				}
				#snapscan-widget .scan-header, .scan-footer {
				  display: none;
				}
				#snapscan-widget .download-links {
				  display: inline-flex;
				  margin-top: 10px;
				}
				#snapscan-widget img {
				  margin-bottom: 0;
				}
				}
			</style>
			<div id="snapscan-widget" style="margin:0 auto;text-align: center; border:none">
				<div class="snap-code-contaner">
				  <img class="snapscan-snap-code" src="' . $qr_image_url . '" width="160" height="160" style="padding:0px; background-color:white; border:none; background:transparent">
			      <b class="scan-header">Scan here to pay</b>
				</div>
                <div class="scan-footer">
                    <img class="logo" src="' . $this->plugin_url() . "/assets/snapscan_images/SnapScan_logo_v1.svg" . '">
                </div>
				<a class="pay-link" href="' . $qr_url . '" target="_blank">
				    <div class="tap-to-pay">
				        <img src="' . $this->plugin_url() . "/assets/snapscan_images/SnapScan_Icon_v1.svg" . '" class="snap-logo">
				        <p class="tap-text">Tap here to pay</p>
                    </div>
                </a>
				<div class="text-box">
				    <p class="download-text">Download the app:</p>
				</div>
				<div class="download-links">
					<a class="link" href="'.$qr_url .'"><img src="' . $this->plugin_url() . "/assets/snapscan_images/apple_icon.svg" . '"></a>
				    <a class="link" href="'.$qr_url .'"><img src="' . $this->plugin_url() . "/assets/snapscan_images/play_icon.svg" . '"></a>
				    <a href="'.$qr_url .'"><img src="' . $this->plugin_url() . "/assets/snapscan_images/huawei_icon.svg" . '"></a>
                </div>
			</div>
		
		</div>';

            $check_order_url = $this->api_endpoint;


            if (strrpos($check_order_url, '?') === false) {
                $check_order_url .= '?merchantReference=' . $order_id;
            } else {
                $check_order_url .= '&merchantReference=' . $order_id;
            }

            $polling_script = '';
            if (in_array($order->get_status(), array('pending', 'failed'))) {
                $polling_script = '
			<script type="text/javascript">
				function pollSnapScanPayment() {
					jQuery.ajaxSetup({
					    headers: {
					        Authorization: "Basic ' . base64_encode($this->settings['snap_api'] . ':' . '') . '"
					    }
					});
					jQuery.getJSON("' . $check_order_url . '" ).then(
					function(r) { // success
					 let l = r.length;
					    if (l == 0) {
					        setTimeout(pollSnapScanPayment, 1000);
					    } else {
					        if(r[l-1].status == "completed"){
					            window.location.replace("' . $order_received_url . '");
					        } else {
					            setTimeout(pollSnapScanPayment, 1000);
					        }
					    }
					},
					function(r) { // fail
					    setTimeout(pollSnapScanPayment, 3000);
					});
				}
				pollSnapScanPayment();
			</script>';
            }
            print $polling_script;
        }

        function process_payment($order_id)
        {

            $order = new WC_Order($order_id);

            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }
    }
}