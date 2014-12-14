<?php

defined('_JEXEC') or die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

/**
 *
 * 
 * this callback have IPN
 * callback URL : http://example.com.ph/index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived
 */

if (!class_exists('vmPSPlugin')) { require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php'); }

class plgVMPaymentDragonpay extends vmPSPlugin 
{
    // instance of class
    public static $_this = false;

    function __construct(& $subject, $config) 
	{
		parent::__construct($subject, $config);
	
		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
	
		$varsToPush = array('dragonpay_merchantid' => array('', 'int'),
			'dragonpay_verifykey' => array('', 'char'),
			'status_pending' => array('', 'char'),
			'status_success' => array('', 'char'),
			'status_canceled' => array('', 'char'),
                        'payment_logos' => array('', 'char'),
			'logoimg' => array('', 'char')
			);
	
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    protected function getVmPluginCreateTableSQL() 
	{
		return $this->createTableSQL('Payment Dragonpay Table');
    }

    function getTableSQLFields() 
	{
		$SQLfields = array(
			'id' => 'tinyint(1) unsigned NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
			'order_number' => 'char(32) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
			'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
			'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
			'payment_currency' => 'char(3) ',
		);
		return $SQLfields;
    }

    function plgVmConfirmedOrder($cart, $order) 
	{
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) 
		{
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) 
		{
			return false;
		}
		$session = JFactory::getSession();
		$return_context = $session->getId();
		$this->_debug = $method->debug;
		$this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
	
		if (!class_exists('VirtueMartModelOrders'))		{ require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' ); }
		if (!class_exists('VirtueMartModelCurrency'))	{ require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php'); }
	
		$new_status = '';
	
		$usrBT = $order['details']['BT'];
		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);
	
		$vendorModel = VmModel::getModel('Vendor');
		$vendorModel->setId(1);
		$vendor = $vendorModel->getVendor();
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
	
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = str_replace(',','',number_format($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false),'2','.',''));
		$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
		
		//to get RM
		$countryquery = 'SELECT `currency_2_code` FROM `#__virtuemart_countries` WHERE `virtuemart_currency_id`="' . $method->virtuemart_country_id . '" ';
		$dbs = &JFactory::getDBO();
		$dbs->setQuery($countryquery);
		$country = $dbs->loadResult();
		
		
		$txnid = uniqid (true);
		$merchant = $method->dragonpay_merchantid;
		$amount = $totalInPaymentCurrency;
		$ccy = $currency_code_3;
        $description = 	"Order No: ".$order['details']['BT']->order_number;
		$email = $address->email;
        $password = $method->dragonpay_verifykey;

		
		//Digest
		$digest_str = "$merchant:$txnid:$amount:$ccy:$description:$email:$password";
		$digest = sha1($digest_str);
		
		
		//mart name
		$martquery = 'SELECT `vendor_store_name` FROM `#__virtuemart_vendors_en_gb` WHERE `virtuemart_vendor_id`="' . $method->virtuemart_vendor_id . '" ';
		$dbs = &JFactory::getDBO();
		$dbs->setQuery($martquery);
		$martname = $dbs->loadResult();
		
		//data need to send to dragonpay  //"email" => $address->email,
		$post_variables = Array(
			"merchantid" => $method->dragonpay_merchantid,
			"txnid" => $txnid,
			"amount" => $totalInPaymentCurrency,
			"ccy" => $currency_code_3,
			"description" => "Order No: ".$order['details']['BT']->order_number,
			"email" => $address->email,
			"digest" => $digest
			);
				
		// Prepare data that should be stored in the database
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['payment_currency'] = $method->payment_currency;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency;
		$this->storePSPluginInternalData($dbValues);
		
		// add spin image
		$html.= '<form action="https://gw.dragonpay.ph/Pay.aspx? method="get" name="vm_dragonpay_form" >';
		$html.= '<input type="image" name="submit" alt="Click to pay with Dragonpay!" />';
		foreach ($post_variables as $name => $value) 
		{
			$html.= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '" />';
		}
		$html.= '</form>';
		
		$html .= ' <script type="text/javascript">';
		$html .= ' document.vm_dragonpay_form.submit();';
		$html .= ' </script>';
		
		// 	2 = don't delete the cart, don't send email and don't redirect
		return $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $new_status);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) 
	{
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) 
		{
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) 
		{
			return false;
		}
		
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
    }

    function plgVmOnPaymentResponseReceived( &$html) 
	{
		$payment_data = JRequest::get('get');
		vmdebug('plgVmOnPaymentResponseReceived', $payment_data);
		
		$order_number 	= $payment_data['orderid'];
		
		$db = JFactory::getDBO();
		$query = 'SELECT ' . $this->_tablename . '.`virtuemart_paymentmethod_id` FROM ' . $this->_tablename. " WHERE  `order_number`= '" . $order_number . "'";
		$db->setQuery($query);
		$virtuemart_paymentmethod_id = $db->loadResult();
		
		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_ids = JRequest::getInt('pm', 0);
				
		$vendorId = 0;
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) 
		{
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) 
		{
			return false;
		}
		
		$txnid 			= $txnid;
		$refno          = $refno;
		$status         = $status;
		$message        = $messge;
		$digest         = $digest;
		
	    
		if (!class_exists('VirtueMartCart'))		{ require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');}
		if (!class_exists('shopFunctionsF'))		{ require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');}
		if (!class_exists('VirtueMartModelOrders'))	{ require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );}
	
		$virtuemart_order_id 	= VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		$payment_name 			= $this->renderPluginName($method);
	
		
		
		//normal return
		if ($virtuemart_paymentmethod_ids)
		{
			if ( $status == "S" ) 
			{
				if (!class_exists('VirtueMartCart'))
					require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
				
				$cart = VirtueMartCart::getCart();
		
				if (!class_exists('VirtueMartModelOrders'))
					require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
				
				$modelOrder = VmModel::getModel('orders');
				$orderitems = $modelOrder->getOrder($virtuemart_order_id);
		
				$new_status = $method->status_success;
				
				$orders["order_status"] 		= $new_status;
				$orders["virtuemart_order_id"] 	= $virtuemart_order_id;
				$orders["customer_notified"] 	= 1;
				$orders["comments"] 			= '';

				$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $orders, true);					
				$cart->emptyCart();
						
				$html = '<table width="50%">' . "\n";
				$html .= '<tr><td width="25%">Payment Name</td>
							  <td width="25%">'.$payment_name.'</td>
						  </tr>
						  <tr><td>Order Number</td>
							  <td>'.$order_number.'</td>
						  </tr>
						  <tr><td>Amount</td>
							  <td>'.$currency.' '.$amount.'</td>
						  </tr>';	
				$html .= '</table>' . "\n";
			
				return $html;
			}
			else
			{
				echo "<div style='color:red'>Payment Failed. Please Make Payment Again!</div>";	
						
				if (!class_exists('VirtueMartModelOrders'))
					require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
				
				$modelOrder 				= VmModel::getModel('orders');
				$orderitems 				= $modelOrder->getOrder($virtuemart_order_id);
		
				$new_status = $method->status_canceled;
				
				$orders["order_status"] 		= $new_status;
				$orders["virtuemart_order_id"] 	= $virtuemart_order_id;
				$orders["customer_notified"] 	= 0;
				$orders["comments"] 			= '';

				$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $orders, true);
			}
		}
		
		//callback
		if ($nbcb && $nbcb == 1)
		{
			echo "CBTOKEN:MPSTATOK";
			
			if ( $status == "00" ) 
			{
				if (!class_exists('VirtueMartCart'))
					require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
				$cart = VirtueMartCart::getCart();
		
				if (!class_exists('VirtueMartModelOrders'))
					require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
				
				$modelOrder 				= VmModel::getModel('orders');
				$orderitems 				= $modelOrder->getOrder($virtuemart_order_id);
		
				$new_status = $method->status_success;
									
				$orders["order_status"] = $new_status;
				$orders["virtuemart_order_id"] = $virtuemart_order_id;
				$orders["customer_notified"] = 1;
				$orders["comments"] = 'Update using Callback';

				$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $orders);
				$cart->emptyCart();
			}
			else
			{
				if (!class_exists('VirtueMartModelOrders'))
					require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
				
				$modelOrder 				= VmModel::getModel('orders');
				$orderitems 				= $modelOrder->getOrder($virtuemart_order_id);
		
				$new_status = $method->status_canceled;
				
				$orders["order_status"] 		= $new_status;
				$orders["virtuemart_order_id"] 	= $virtuemart_order_id;
				$orders["customer_notified"] 	= 0;
				$orders["comments"] 			= 'Update using Callback';

				$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $orders);
			}
		}
    }
	
    function plgVmOnUserPaymentCancel() 
	{
		if (!class_exists('VirtueMartModelOrders'))
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
	
		$order_number = JRequest::getVar('on');
		if (!$order_number)
			return false;
		$db = JFactory::getDBO();
		$query = 'SELECT ' . $this->_tablename . '.`virtuemart_order_id` FROM ' . $this->_tablename. " WHERE  `order_number`= '" . $order_number . "'";
	
		$db->setQuery($query);
		$virtuemart_order_id = $db->loadResult();
		if (!$virtuemart_order_id) 
		{
			return null;
		}
		$this->handlePaymentUserCancel($virtuemart_order_id);	
		return true;
    }

    /**
     * Display stored payment data for an order
     * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) 
	{
		if (!$this->selectedThisByMethodId($payment_method_id)) 
		{
			return null; // Another method was selected, do nothing
		}
	
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) 
		{
			return '';
		}
		$this->getPaymentCurrency($paymentTable);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $paymentTable->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= '<tr><td width="50%">Payment Name</td><td>'.$paymentTable->payment_name.'</td></tr>';
		$html .= '</table>' . "\n";
		return $html;
		
		
    }


    function getCosts(VirtueMartCart $cart, $method, $cart_prices) 
	{
		if (preg_match('/%$/', $method->cost_percent_total)) 
		{
			$cost_percent_total = substr($method->cost_percent_total, 0, -1);
		} else 
		{
			$cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
    
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices) 
	{
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
	
		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
			OR
			($method->min_amount <= $amount AND ($method->max_amount == 0) ));
	
		$countries = array();
		if (!empty($method->countries)) 
		{
			if (!is_array($method->countries)) 
			{
				$countries[0] = $method->countries;
			} 
			else 
			{
				$countries = $method->countries;
			}
		}
		
		if (!is_array($address)) 
		{
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}
	
		if (!isset($address['virtuemart_country_id']))
			$address['virtuemart_country_id'] = 0;
			
		if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) 
		{
			if ($amount_cond) 
			{
				return true;
			}
		}
	
		return false;
    }

     /*
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {

	return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
   
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
	return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
	return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /*
     * plgVmonSelectedCalculatePricePayment
   
     *
     */

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
	return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
  o plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
	return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
    
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
	  $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
    ckoutCheckDataPayment($psType, VirtueMartCart $cart) {
      return null;
      }
     */

    /**
   
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id) {
	return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
   
     */

    /**
   
      }
     */
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
	return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
	return $this->setOnTablePluginParams($name, $id, $table);
    }

}

// No closing tag
