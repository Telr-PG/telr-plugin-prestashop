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
class Telr_PaymentsIvpcallbackModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
  
	public function postProcess()
    {
        PrestaShopLogger::addLog("TelrIvpCallbackRequest: " . json_encode($_POST), 1);

        if (isset($_GET['cart_id']) && !empty($_GET['cart_id']) && !empty($_POST)) {
            // proceed to update order payment details:
            $cartIdExtract = explode("_", $_POST['tran_cartid']);
            $order_id = $cartIdExtract[0];
            
            if ($order_id == $_GET['cart_id']) {
                try {
                    
                    $tranType = $_POST['tran_type'];
                    $tranStatus = $_POST['tran_authstatus'];
                    $tran_id = $_POST['tran_ref'];
                    $amount = $_POST['tran_amount'];

                    if ($tranStatus == 'A') {
                        switch ($tranType) {
                            case '1':
                            case '4':
                            case '7':
							
                                $history = new OrderHistory();
								
								$order_id = Order::getOrderByCartId($order_id);
								
                                $history->id_order = (int)$order_id;
							
								if(Configuration::get('TELR_PAYMENTS_DEFAULT_STATUS') == 'AUTO'){
									$history->changeIdOrderState(Configuration::get('PS_OS_PAYMENT'), (int)($order_id));  
								}else{
									$history->changeIdOrderState((int) Configuration::get(Configuration::get('TELR_PAYMENTS_DEFAULT_STATUS')), (int)($order_id)); 
								}
                                $history->addWithemail(true);
                                break;

                            case '2':
                            case '6':
                            case '8':
                                $history = new OrderHistory();
                                $order_id = Order::getOrderByCartId($order_id);
                                $history->id_order = (int)$order_id;
                                $history->changeIdOrderState(Configuration::get('PS_OS_CANCELED'), (int)($order_id)); 
                                $history->addWithemail(true);
                                break;

                            case '3':
                                $history = new OrderHistory();
                                $order_id = Order::getOrderByCartId($order_id);
                                $history->id_order = (int)$order_id;
                                $history->changeIdOrderState(Configuration::get('PS_OS_REFUND'), (int)($order_id)); 
                                $history->addWithemail(true);
                                break;

                            default:
                                // No action defined
                                break;
                        }
                    }
                } catch (Exception $e) {
                    // Error Occurred While processing request.
                     die('Error Occurred While processing request');
                }
            } else {
                 die('Cart id mismatch');
            }
            
            exit;
        }else{
            die('Invalid Cart id');
            exit;
        }
        echo "Request Processed"; exit;
    }
}
