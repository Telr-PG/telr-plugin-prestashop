<?php
/**
*  Prestashop Telr Payment module
*
* This program is free software: you can redistribute it and/or modify it under the terms
* of the GNU General Public License as published by the Free Software Foundation, either
* version 3 of the License, or (at your option) any later version.
* This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
* without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
* See the GNU General Public License for more details. You should have received a copy of the
* GNU General Public License along with this program. If not, see <http://www.gnu.org/licenses/>.
*
* @category   Payment
* @package    Telr
* @author     Telr Dev <support@telr.com>
* @copyright Copyright (c) 2018  Telr. (http://www.telr.com)
*/

/**
 * @since 1.5.0
 */
class Telr_PaymentsProcessApplepayModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */    
    public function postProcess()
    {	
        $objTransaction='';
        $objError='';
				
        if(isset($_POST['applepaydata'])) {
            $applePayData = json_decode($_POST['applepaydata'], true);
            if(isset($applePayData['paymentData']) && isset($applePayData['paymentMethod']) && isset($applePayData['transactionIdentifier'])) {
					
				$cart = $this->context->cart;
				if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
					Tools::redirect('index.php?controller=order&step=1');
				}

				// Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
				$authorized = false;
				foreach (Module::getPaymentModules() as $module) {
					if ($module['name'] == 'telr_payments' || $module['name'] == 'Telr_Payments') {
						$authorized = true;
						break;
					}
				}

				if (!$authorized) {
					die($this->module->l('This payment method is not available.', 'validation'));
				}

				$this->context->smarty->assign([
					'params' => $_REQUEST,
				]);

				$shop = new Shop(Configuration::get('PS_SHOP_DEFAULT'));
				$address    = new Address((int)($this->context->cart->id_address_invoice));
				$total_pay = (float)$this->context->cart->getOrderTotal(true, Cart::BOTH);

				$cart_id=$this->context->cart->id;
				$trandesc=trim(str_replace('{order}',$cart_id, Configuration::get('TELR_PAYMENTS_TRANDESC')));
				if (empty($trandesc)) {
					$trandesc="Order #".$cart_id;
				}

				$cart_id = $cart_id."_".uniqid();

				$data = array(
					'ivp_method'    => "applepay",
					'ivp_source'    => "Prestashop "._PS_VERSION_,
					'ivp_store' => Configuration::get('TELR_PAYMENTS_STOREID') ,
					'ivp_authkey'   => Configuration::get('TELR_APPLEPAY_SECRET'),
					'ivp_test'  => "0",
					'ivp_cart'  => $cart_id,
					'ivp_amount'    => $total_pay,
					'ivp_desc'  => $trandesc,
					'ivp_trantype' => "sale",
					'ivp_tranclass' => "ecom",
					'ivp_currency'  => $this->context->currency->iso_code,
					'bill_fname'    => $address->firstname,
					'bill_sname'    => $address->lastname,
					'bill_addr1'    => $address->address1,
					'bill_addr2'    => $address->address2,
					'bill_phone1'   => $address->phone,
					'bill_city' => $address->city,
					'bill_region'   => $address->city,
					'bill_zip'  => $address->postcode,
					'bill_email'    => $this->context->customer->email,
					'bill_country'  => $this->context->country->iso_code ,
					'applepay_enc_version'  => $applePayData['paymentData']['version'],
					'applepay_enc_paydata'  => urlencode($applePayData['paymentData']['data']),
					'applepay_enc_paysig'   => urlencode($applePayData['paymentData']['signature']),
					'applepay_enc_pubkey'   => urlencode($applePayData['paymentData']['header']['ephemeralPublicKey']),
					'applepay_enc_keyhash'  => $applePayData['paymentData']['header']['publicKeyHash'],
					'applepay_tran_id'      => $applePayData['paymentData']['header']['transactionId'],
					'applepay_card_desc'    => $applePayData['paymentMethod']['type'],
					'applepay_card_scheme'  => $applePayData['paymentMethod']['displayName'],
					'applepay_card_type'    => $applePayData['paymentMethod']['network'],
					'applepay_tran_id2'     => $applePayData['transactionIdentifier'],
				);

				PrestaShopLogger::addLog("TelrOrderCreateRequest: " . json_encode($data), 1);

				$response  = $this->apiRequest($data);
                PrestaShopLogger::addLog("TelrOrderCreateResponse: " . json_encode($response), 1);
				
                if (isset($response['transaction'])) { $objTransaction = $response['transaction']; }
                if (isset($response['error'])) { $objError = $response['error']; }
                if (is_array($objError)) {
                    $this->errors[] = "Unable to process your payment. Error: " . $objError['message'] . ' ' . $objError['note'] . ' ' . $objError['details'];;
                    $this->redirectWithNotifications('index.php?controller=order&step=1');
                }else {
                    $cart = $this->context->cart;
                    $currency = $this->context->currency;
                    $total = (float)$cart->getOrderTotal(true, Cart::BOTH);
                    $extra_vars = array('transaction_id' => Tools::getValue('transaction_id'));
                    $customer = new Customer($cart->id_customer);
                    if (!Validate::isLoadedObject($customer))
                        Tools::redirect('index.php?controller=order&step=1');
					
                    $txStatus = $objTransaction['status'];
                    $txRef = $objTransaction['ref'];
                    if ($txStatus == 'A') {
                        if(Configuration::get('TELR_PAYMENTS_DEFAULT_STATUS') == 'AUTO'){
                            $this->module->validateOrder($cart->id,
                                Configuration::get('PS_OS_PAYMENT'), $total,
                                $this->module->displayName . " Ref: " . $txRef, NULL, $extra_vars,
                                (int)$currency->id, false, $customer->secure_key
                            );
                        }else{
                            $this->module->validateOrder($cart->id,
                                Configuration::get(Configuration::get('TELR_PAYMENTS_DEFAULT_STATUS')), $total,
                                $this->module->displayName . " Ref: " . $txRef, NULL, $extra_vars,
                                (int)$currency->id, false, $customer->secure_key
                            );
                        }
                        $this->redirectWithNotifications('index.php?controller=order-confirmation&id_cart='.$cart->id.'&id_module='.$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$this->context->customer->secure_key);
                    }
                }
            }
        }
        $this->errors[] = "Payment Failed! Reason: Invalid Request!";
        $this->redirectWithNotifications('index.php?controller=order&step=1');		
    }

    private function apiRequest($data){
		
        $telrAPIURL = Configuration::get('TELR_PAYMENTS_APIURL')."/gateway/remote.json";
		
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $telrAPIURL);
        curl_setopt($ch, CURLOPT_POST, count($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
        $result = curl_exec($ch);
        $returnData = json_decode($result,true);
        print curl_error($ch);
        curl_close($ch);
        return $returnData;
    }
}
