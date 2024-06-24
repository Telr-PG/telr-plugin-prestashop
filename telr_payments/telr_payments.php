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

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Telr_Payments extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = array();

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;

    public function __construct()
    {
        $this->name = 'telr_payments';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->author = 'telr.com';
        $this->controllers = array('validation');
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->bootstrap = true;
        parent::__construct();

        $config = Configuration::getMultiple(array('TELR_PAYMENTS_STOREID', 'TELR_PAYMENTS_SECRET'));

        $this->displayName = $this->l('Telr Secure Payments');
        $this->description = $this->l('Process transactions through the Telr gateway');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
        if (function_exists('curl_init') == false) {
            $this->warning = $this->l('To be able to use this module, please activate cURL (PHP extension).');
        } else if ((empty($config['TELR_PAYMENTS_STOREID'])) || (empty($config['TELR_PAYMENTS_SECRET']))) {
            $this->warning = $this->l('Store ID and authentication key must be configured before using this module.');
        }
        $this->module_link = $this->context->link->getAdminLink('AdminModules', true).'&configure='.$this->name;
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHook('paymentOptions') || !$this->registerHook('paymentReturn') || !$this->installOrderState()) {
            return false;
        }
        if (Shop::isFeatureActive()){
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install() &&
        $this->registerHook('paymentOptions') &&
        $this->registerHook('paymentReturn') &&
        Configuration::updateValue('TELR_PAYMENTS_TESTMODE', 1) &&
        Configuration::updateValue('TELR_PAYMENTS_IFRAMEMODE', 0) &&
        Configuration::updateValue('TELR_PAYMENTS_LANGUAGE', 'en') &&
        Configuration::updateValue('TELR_PAYMENTS_DEFAULT_STATUS', 'PS_OS_PAYMENT') &&
        Configuration::updateValue('TELR_PAYMENTS_TRANDESC', 'Your order from StoreName') &&
		Configuration::updateValue('TELR_PAYMENTS_APIURL', 'https://secure.telr.com');
        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('TELR_PAYMENTS_STOREID')
            || !Configuration::deleteByName('TELR_PAYMENTS_SECRET')
            || !Configuration::deleteByName('TELR_PAYMENTS_APIURL')
            || !Configuration::deleteByName('TELR_PAYMENTS_TRANDESC')
            || !Configuration::deleteByName('TELR_PAYMENTS_TESTMODE')
            || !Configuration::deleteByName('TELR_PAYMENTS_IFRAMEMODE')
            || !Configuration::deleteByName('TELR_PAYMENTS_LANGUAGE')
            || !Configuration::deleteByName('TELR_PAYMENTS_DEFAULT_STATUS')
        ){
            return false;
        }
        return true;
    }

    /**
     * Create order state
     * @return boolean
     */
    public function installOrderState()
    {
        if (!Configuration::get('TELR_OS_PAYMENT_PENDING')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('TELR_OS_PAYMENT_PENDING')))) {
            $order_state = new OrderState();
            $order_state->name = array();
            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Payment Pending for Telr Gateway';
            }
            $order_state->send_email = false;
            $order_state->color = '#0d6448';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;
            if ($order_state->add()) {
                $source = _PS_MODULE_DIR_.'telr_payments/logo.png';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int) $order_state->id.'.gif';
                copy($source, $destination);
            }
            Configuration::updateValue('TELR_OS_PAYMENT_PENDING', (int) $order_state->id);
        }

        if (!Configuration::get('TELR_OS_PAYMENT_HOLD')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('TELR_OS_PAYMENT_HOLD')))) {
            $order_state = new OrderState();
            $order_state->name = array();
            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Payment on hold at Telr';
            }
            $order_state->send_email = false;
            $order_state->color = '#0d6448';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;
            if ($order_state->add()) {
                $source = _PS_MODULE_DIR_.'telr_payments/logo.png';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int) $order_state->id.'.gif';
                copy($source, $destination);
            }
            Configuration::updateValue('TELR_OS_PAYMENT_HOLD', (int) $order_state->id);
        }
        return true;
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit'.$this->name)) {
            if (function_exists('curl_init') == false) {
                $output .= $this->displayError($this->l('To be able to use this module, please activate cURL (PHP extension).'));
            } else {
                $store_id = strval(Tools::getValue('TELR_PAYMENTS_STOREID'));
                $store_secret = strval(Tools::getValue('TELR_PAYMENTS_SECRET'));
                $store_trandesc = strval(Tools::getValue('TELR_PAYMENTS_TRANDESC'));
                $store_testmode = strval(Tools::getValue('TELR_PAYMENTS_TESTMODE'));
                $store_iframemode = strval(Tools::getValue('TELR_PAYMENTS_IFRAMEMODE'));
                $store_language = strval(Tools::getValue('TELR_PAYMENTS_LANGUAGE'));
                $store_orderstatus = strval(Tools::getValue('TELR_PAYMENTS_DEFAULT_STATUS'));
                if (empty($store_id) || empty($store_secret)) {
                    $output .= $this->displayError($this->l('Store ID and Authentication Key must be entered first'));
                } else {
                    Configuration::updateValue('TELR_PAYMENTS_STOREID', $store_id);
                    Configuration::updateValue('TELR_PAYMENTS_SECRET', $store_secret);
                    Configuration::updateValue('TELR_PAYMENTS_TRANDESC', $store_trandesc);
                    Configuration::updateValue('TELR_PAYMENTS_TESTMODE', $store_testmode);
                    Configuration::updateValue('TELR_PAYMENTS_IFRAMEMODE', $store_iframemode);
                    Configuration::updateValue('TELR_PAYMENTS_LANGUAGE', $store_language);
                    Configuration::updateValue('TELR_PAYMENTS_DEFAULT_STATUS', $store_orderstatus);
                    Configuration::updateValue('TELR_PAYMENTS_APIURL', 'https://secure.telr.com');
                    $output .= $this->displayConfirmation($this->l('Settings updated'));
                }
            }
        }
        return $output.$this->displayForm();
    }

    public function displayForm()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Store ID'),
                    'name' => 'TELR_PAYMENTS_STOREID',
                    'desc' => 'Enter your Telr Store ID',
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Authentication Key'),
                    'name' => 'TELR_PAYMENTS_SECRET',
                    'desc' => 'This value must match the value configured in the hosted payment page v2 settings',
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Transaction Description'),
                    'name' => 'TELR_PAYMENTS_TRANDESC',
                    'desc' => 'This controls the transaction description shown within the hosted payment page.',
                    'required' => true
                ),
                array(
                    'type' => 'radio',
                    'label' => $this->l('Test Mode'),
                    'name' => 'TELR_PAYMENTS_TESTMODE',
                    'class'     => 't',
                    'is_bool'   => true,

                    'values'    => array(
                        array(
                            'id'    => 'active_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id'    => 'active_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    )
                ),
                array(
                    'type' => 'select',                          
                    'label' => $this->l('Payment Mode'),         
                    'desc' => $this->l('Choose a payment mode. SSL is required for Framed mode. Standard Mode will be used if SSL is not available.'),  
                    'name' => 'TELR_PAYMENTS_IFRAMEMODE',            
                    'required' => true,                              
                    'options' => array(
                        'query' => array(
                            array(
                                'id_option' => 0,      
                                'name' => 'Standard Mode'   
                            ),
                            array(
                                'id_option' => 2,
                                'name' => 'Framed Mode'
                            ),
							array(
                                'id_option' => 10,
                                'name' => 'Seamless Mode'
                            ),
                        ),                           
                        'name' => 'name',                               
                        'id' => 'id_option'                               
                    )
                ),
                array(
                    'type' => 'select',                          
                    'label' => $this->l('Language'),         
                    'desc' => $this->l('Choose a language for payment page.'),  
                    'name' => 'TELR_PAYMENTS_LANGUAGE',            
                    'required' => true,                              
                    'options' => array(
                        'query' => array(
                            array(
                                'id_option' => 'en',      
                                'name' => 'English'   
                            ),
                            array(
                                'id_option' => 'ar',
                                'name' => 'Arabic'
                            ),
                        ),                           
                        'name' => 'name',                               
                        'id' => 'id_option'                               
                    )
                ),
                array(
                    'type' => 'select',                          
                    'label' => $this->l('Default Order Status'),         
                    'desc' => $this->l('Choose a default order status for successful payment.'),  
                    'name' => 'TELR_PAYMENTS_DEFAULT_STATUS',            
                    'required' => true,                              
                    'options' => array(
                        'query' => array(
                            array(
                                'id_option' => 'PS_OS_PAYMENT',
                                'name' => 'Payment Successful'
                            ),
                            array(
                                'id_option' => 'PS_OS_PREPARATION',
                                'name' => 'Preparing Order'
                            ),
                            array(
                                'id_option' => 'PS_OS_SHIPPING',
                                'name' => 'Order Shipped'
                            ),
                            array(
                                'id_option' => 'PS_OS_DELIVERED',
                                'name' => 'Order Delivered'
                            ),
                            array(
                                'id_option' => 'PS_OS_CANCELED',
                                'name' => 'Order Canceled'
                            ),
                            array(
                                'id_option' => 'PS_OS_REFUND',
                                'name' => 'Order Refunded'
                            ),
                            array(
                                'id_option' => 'PS_OS_OUTOFSTOCK',
                                'name' => 'Product Out of Stock'
                            ),
                            array(
                                'id_option' => 'TELR_OS_PAYMENT_PENDING',
                                'name' => 'Payment Pending for Telr Gateway'
                            ),
                            array(
                                'id_option' => 'TELR_OS_PAYMENT_HOLD',
                                'name' => 'Payment on hold at Telr'
                            ),
                        ),                           
                        'name' => 'name',                               
                        'id' => 'id_option'                               
                    )
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'button'
            )
        );

        $helper = new HelperForm();

        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = array(
            'save' =>
            array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                '&token='.Tools::getAdminTokenLite('AdminModules'),
            ),
            'back' => array(
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        $helper->fields_value['TELR_PAYMENTS_STOREID'] = Configuration::get('TELR_PAYMENTS_STOREID');
        $helper->fields_value['TELR_PAYMENTS_SECRET'] = Configuration::get('TELR_PAYMENTS_SECRET');
        $helper->fields_value['TELR_PAYMENTS_TRANDESC'] = Configuration::get('TELR_PAYMENTS_TRANDESC');
        $helper->fields_value['TELR_PAYMENTS_TESTMODE'] = Configuration::get('TELR_PAYMENTS_TESTMODE');
        $helper->fields_value['TELR_PAYMENTS_IFRAMEMODE'] = Configuration::get('TELR_PAYMENTS_IFRAMEMODE');
        $helper->fields_value['TELR_PAYMENTS_LANGUAGE'] = Configuration::get('TELR_PAYMENTS_LANGUAGE');
        $helper->fields_value['TELR_PAYMENTS_DEFAULT_STATUS'] = Configuration::get('TELR_PAYMENTS_DEFAULT_STATUS');

        return $helper->generateForm($fields_form);
    }


    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $payment_options = [];
        
        $isSSl = (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'] == "on") ? true : false;
	    $isSSl = true;

		if(Configuration::get('TELR_PAYMENTS_IFRAMEMODE') == 2 && $isSSl){
			//$payment_options[] = $this->getIframePaymentOption();
			$payment_options[] = $this->getEmbeddedPaymentOption();
		}elseif(Configuration::get('TELR_PAYMENTS_IFRAMEMODE') == 10 && $isSSl){
			$payment_options[] = $this->getEmbeddedPaymentOption();
		}else{
			$payment_options[] = $this->getExternalPaymentOption();
		}		
        return $payment_options;
    }

    public function getExternalPaymentOption()
    {
        $cardsList = $this->getTelrSupportedNetworks();
        $supportedCards = $this->getSupportedCardList($cardsList);
        $this->context->smarty->assign(['supportedCards'=>$supportedCards]);

        $externalOption = new PaymentOption();
        $externalOption->setModuleName($this->name)
		->setCallToActionText($this->l('Credit/Debit Card'))
        ->setAction($this->context->link->getModuleLink($this->name, 'process', array(), true))
        ->setAdditionalInformation($this->context->smarty->fetch('module:telr_payments/views/templates/front/payment_infos.tpl'))
        ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/payment.jpg'));

        return $externalOption;
    }

    public function getIframePaymentOption()
    {
        $cardsList = $this->getTelrSupportedNetworks();
        $supportedCards = $this->getSupportedCardList($cardsList);
        $this->context->smarty->assign(['supportedCards'=>$supportedCards]);

        $iframeOption = new PaymentOption();
        $iframeOption->setModuleName($this->name)
		->setCallToActionText($this->l('Credit/Debit Card'))
        ->setAction($this->context->link->getModuleLink($this->name, 'process', array(), true))
        ->setAdditionalInformation($this->context->smarty->fetch('module:telr_payments/views/templates/front/payment_infos.tpl'))
        ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/payment.jpg'));

        return $iframeOption;
    }
	
	 /**
     * Factory of PaymentOption for Embedded Payment
     *
     * @return PaymentOption
     */
    private function getEmbeddedPaymentOption()
    {
        $embeddedOption = new PaymentOption();
        $embeddedOption->setModuleName($this->name)
		->setCallToActionText($this->l('Credit/Debit Card'));
        $embeddedOption->setForm($this->generateEmbeddedForm());

        return $embeddedOption;
    }
	
	
	private function generateEmbeddedForm()
    {
		$storeId = Configuration::get('TELR_PAYMENTS_STOREID');
		$currencyCode = $this->context->currency->iso_code;
		$testMode = Configuration::get('TELR_PAYMENTS_TESTMODE');
		$storelang = Configuration::get('TELR_PAYMENTS_LANGUAGE');
		$iframemod = Configuration::get('TELR_PAYMENTS_IFRAMEMODE');
		$savedCards = [];
		$frameHeight = 320;
		
		if (Tools::getShopProtocol() == 'http://' && $this->context->customer->isLogged())
        {            
			$savedCards = $this->getTelrSavedCards($this->context->customer->id);
			if(count($savedCards) > 0){
				$frameHeight += 30;
				$frameHeight += (count($savedCards) * 110);
			}
        }
		
        $seamlessUrl = Configuration::get('TELR_PAYMENTS_APIURL')."/jssdk/v2/token_frame.html?token=" . rand(1111,9999)."&lang=".$storelang;
		
        $cardsList = $this->getTelrSupportedNetworks();
        $supportedCards = $this->getSupportedCardList($cardsList);
		
        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->name, 'process', array(), true),
			'storeid' => $storeId,
			'currency_code' => $currencyCode,
			'test_mode' => $testMode,
			'seamless_url' => $seamlessUrl,
			'saved_cards' => json_encode($savedCards),
			'frame_height' => $frameHeight,
			'iframemod' => $iframemod,
			'supportedCards'=>$supportedCards			
        ]);

        return $this->context->smarty->fetch('module:telr_payments/views/templates/front/paymentOptionEmbeddedForm.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }
	
	
	protected function getTelrSavedCards($custId)
    {
        $telrCards = array();

        $storeId = Configuration::get('TELR_PAYMENTS_STOREID');
        $authKey = Configuration::get('TELR_PAYMENTS_SECRET');
        $testMode  = Configuration::get('TELR_PAYMENTS_TESTMODE');

        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => Configuration::get('TELR_PAYMENTS_APIURL')."/gateway/savedcardslist.json",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_SSL_VERIFYHOST => 0,
          CURLOPT_SSL_VERIFYPEER => 0,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_POSTFIELDS => "api_storeid=" . $storeId . "&api_authkey=" . $authKey . "&api_testmode=" . $testMode . "&api_custref=" . $custId,
          CURLOPT_HTTPHEADER => array(
            "cache-control: no-cache",
            "content-type: application/x-www-form-urlencoded",
          ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if (!$err) {
            $resp = json_decode($response, true);
            if(isset($resp['SavedCardListResponse']) && $resp['SavedCardListResponse']['Code'] == 200){
                if(isset($resp['SavedCardListResponse']['data'])){
                    foreach ($resp['SavedCardListResponse']['data'] as $key => $row) {
                        $telrCards[] = array(
                            'txn_id' => $row['Transaction_ID'],
                            'name' => $row['Name']
                        );
                    }
                }
            }
        }

        return $telrCards;
    }

    protected function getTelrSupportedNetworks()
	{
		
	    $storeId = Configuration::get('TELR_PAYMENTS_STOREID');
        $currencyCode = $this->context->currency->iso_code;
        $testMode  = Configuration::get('TELR_PAYMENTS_TESTMODE');
		
        $data =array(
            'ivp_store' => $storeId,			
            'ivp_currency' => $currencyCode,
            'ivp_test' => $testMode
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, Configuration::get('TELR_PAYMENTS_APIURL').'/gateway/api_store_terminals.json');		
        curl_setopt($ch, CURLOPT_POST, count($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
        $results = curl_exec($ch);
        $results = preg_replace('/,\s*([\]}])/m', '$1', $results);
        $results = json_decode($results, true);
        
        if (isset($results['StoreTerminalsResponse']['CardList'])){
           return  $results['StoreTerminalsResponse']['CardList'];
        }else{
           return array();
        }
    }

    protected function getSupportedCardList($cardsList)
	{
        $supportedCards = array(); 
	    if(!empty($cardsList)){
            foreach($cardsList as $card){
                if($card == 'VISA'){
                    $supportedCards[] = Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/images/visa.png');
                }elseif($card == 'MASTERCARD'){
                    $supportedCards[] = Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/images/mastercard.png');
                }elseif($card == 'JCB'){
                    $supportedCards[] = Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/images/jcb.png');
                }elseif($card == 'MADA'){
                    $supportedCards[] = Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/images/mada.png');
                }elseif($card == 'AMEX'){
                    $supportedCards[] = Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/images/amex.png');
                }elseif($card == 'MAESTRO'){
                    $supportedCards[] = Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/images/maestro.png');
                }
            }
        }
        return 	$supportedCards;		
    }
}
