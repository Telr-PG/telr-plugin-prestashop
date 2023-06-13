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
class Telr_PaymentsProcessModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */

    private $telrAPIURL = "https://secure.telr.com/gateway/order.json";

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

        $ivpcallback_url = $this->context->link->getModuleLink('telr_payments', 'ivpcallback') . "?cart_id=" . $cart_id;

        $cart_id=$cart_id."_".uniqid();
        $validation_url = $this->context->link->getModuleLink('telr_payments', 'response');

        $framedMode = 0;
        $isSSl = (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'] == "on") ? true : false;
        //$isSSl = true;

        if(Configuration::get('TELR_PAYMENTS_IFRAMEMODE') == 2 && $isSSl){
            $framedMode = 2;
        }

        $data = array(
            'ivp_method'    => "create",
            'ivp_source'    => "Prestashop "._PS_VERSION_,
            'ivp_store' => Configuration::get('TELR_PAYMENTS_STOREID') ,
            'ivp_authkey'   => Configuration::get('TELR_PAYMENTS_SECRET'),
            'ivp_test'  => Configuration::get('TELR_PAYMENTS_TESTMODE'),
            'ivp_cart'  => $cart_id,
            'ivp_amount'    => $total_pay,
            'ivp_desc'  => $trandesc,
            'ivp_framed' => $framedMode,
            'return_auth'   => $validation_url,
            'return_can'    => $validation_url,
            'return_decl'   => $validation_url,
            'ivp_update_url'   => $ivpcallback_url,
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
            'ivp_lang' => Configuration::get('TELR_PAYMENTS_LANGUAGE'),
        );

        if (Tools::getShopProtocol() == 'https://' && $this->context->customer->isLogged())
        {
            $data['bill_custref'] = $this->context->customer->id;
        }

        PrestaShopLogger::addLog("TelrOrderCreateRequest: " . json_encode($data), 1);

        $response  = $this->apiRequest($data);
        $ref = trim($response['order']['ref']);
        $url= trim($response['order']['url']);

        PrestaShopLogger::addLog("TelrOrderCreateResponse: " . json_encode($response), 1);

        if (empty($ref) || empty($url)){
            $this->setTemplate('module:telr_payments/views/templates/front/payment_error.tpl');
            return false;
        }
        else{
            $this->context->cookie->__set('orderref', $ref);

            if($framedMode == 0){
                Tools::redirect($url);
            }else{
                parent::initContent();
                $this->context->smarty->assign([
                    'src' => $url,
                ]);

                $this->setTemplate('module:telr_payments/views/templates/front/iframe.tpl');            
            }
        }   
    }

    private function apiRequest($data){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->telrAPIURL);
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
