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
class Telr_PaymentsResponseModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    
	public function postProcess()
    {
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
            die($this->module->l('This payment method is not available.', 'respons'));
        }

        if(isset($this->context->cookie->orderref) && $this->checkOrder($this->context->cookie->orderref)){
            $this->redirectWithNotifications('index.php?controller=order-confirmation&id_cart='.$cart->id.'&id_module='.$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$this->context->customer->secure_key);        
        }else{
            $this->redirectWithNotifications('index.php?controller=order&step=1');
            //$this->setTemplate('module:telr_payments/views/templates/front/payment_error.tpl');
        }
    }

    private function checkOrder($order_ref) {
        $data = array('ivp_method' => "check",
            'ivp_store'=> Configuration::get('TELR_PAYMENTS_STOREID') ,
            'ivp_authkey' => Configuration::get('TELR_PAYMENTS_SECRET'),
            'order_ref' => $order_ref,
        );

        $response = $this->apiRequest($data);
        
        PrestaShopLogger::addLog("TelrPaymentResponse: " . json_encode($response), 1);


        if (array_key_exists("order", $response)) {

            $cart = $this->context->cart;
            $currency = $this->context->currency;
            $total = (float)$cart->getOrderTotal(true, Cart::BOTH);
            $extra_vars = array('transaction_id' => Tools::getValue('transaction_id'));
            $customer = new Customer($cart->id_customer);
            if (!Validate::isLoadedObject($customer))
                Tools::redirect('index.php?controller=order&step=1');


            $ordStatus = $response['order']['status']['code'];
            $txStatus = $response['order']['transaction']['status'];
            $txRef = $response['order']['transaction']['ref'];

            if (($ordStatus == -1) || ($ordStatus == -2) || ($ordStatus == -3)) {
                // Order status EXPIRED (-1) or CANCELLED (-2)
                /*$this->module->validateOrder($cart->id,
                    Configuration::get('PS_OS_CANCELED'), $total,
                    $this->module->displayName . " Ref: " . $txRef, NULL, $extra_vars,
                    (int)$currency->id, false, $customer->secure_key
                );*/

                if($ordStatus == -1){
                    $txnMessage = "Payment Expired"; 
                    $this->errors[] = $txnMessage;
                }
                if($ordStatus == -2){
                    $txnMessage = "Payment Cancelled"; 
                    $this->errors[] = $txnMessage;
                }
                if($ordStatus == -3){
                    $txnMessage = (isset($response['order']['transaction']['message'])) ? $response['order']['transaction']['message'] : "Payment Failed"; 
                    $this->errors[] = "Payment Failed! Reason: " . $txnMessage;
                }
                return false;
            }
            if ($ordStatus==4) {
                // Order status PAYMENT_REQUESTED (4)
                $this->module->validateOrder($cart->id,
                    Configuration::get('TELR_OS_PAYMENT_PENDING'), $total,
                    $this->module->displayName . " Ref: " . $txRef, NULL, $extra_vars,
                    (int)$currency->id, false, $customer->secure_key
                ); 
                return true;
            }
            if ($ordStatus==2 || $ordStatus==3) {
                // Order status PAID (3)
                if ($txStatus=='P') {
                    // Transaction status of pending or held
                    $this->module->validateOrder($cart->id,
                        Configuration::get('TELR_OS_PAYMENT_PENDING'), $total,
                        $this->module->displayName . " Ref: " . $txRef, NULL, $extra_vars,
                        (int)$currency->id, false, $customer->secure_key
                    );
                    return true;
                }
                if ($txStatus=='H') {
                    // Transaction status of pending or held
                    $this->module->validateOrder($cart->id,
                        Configuration::get('TELR_OS_PAYMENT_HOLD'), $total,
                        $this->module->displayName . " Ref: " . $txRef, NULL, $extra_vars,
                        (int)$currency->id, false, $customer->secure_key
                    );
                    return true;
                }
                if ($txStatus=='A') {
                    // Transaction status = authorised
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

                    return true;
                }
            }
        }
    }

    private function apiRequest($data){
		
        $telrAPIURL = Configuration::get('TELR_PAYMENTS_APIURL')."/gateway/order.json";
		
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
