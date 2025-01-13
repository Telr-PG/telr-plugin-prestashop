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
class Telr_PaymentsValidationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        if (Tools::getValue('ajax') == 1) {
            $action = Tools::getValue('action');
            if ($action === 'appleSessionValidation') {
                $this->appleSessionValidation();
            }
        }

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

        $this->setTemplate('module:telr_payments/views/templates/front/payment_return.tpl');
    }

    public function appleSessionValidation()
    {
        $url          = $_POST['url'];
        $domain       = Configuration::get('TELR_APPLEPAY_DOMAIN');
        $display_name = Configuration::get('TELR_APPLEPAY_DISPLAY_NAME');
        $merchant_id     = Configuration::get('TELR_APPLEPAY_MERCHANT_ID');
        $certificate     = Configuration::get('TELR_APPLEPAY_CERTIFICATE_PATH');
        $certificate_key = Configuration::get('TELR_APPLEPAY_CERTIFICATE_KEY_PATH');

        if (
            'https' === parse_url( $url, PHP_URL_SCHEME ) &&
            substr( parse_url( $url, PHP_URL_HOST ), - 10 ) === '.apple.com'
        ) {
            $ch = curl_init();
            $data =
                '{
                  "merchantIdentifier":"' . $merchant_id . '",
                  "domainName":"' . $domain . '",
                  "displayName":"' . $display_name . '"
              }';
            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_SSLCERT, $certificate );
            curl_setopt( $ch, CURLOPT_SSLKEY, $certificate_key );
            curl_setopt( $ch, CURLOPT_POST, 1 );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );

            if ( curl_exec( $ch ) === false ) {
                echo '{"curlError":"' . curl_error( $ch ) . '"}';
            }
            curl_close( $ch );            
        }
		exit();
    }

}
