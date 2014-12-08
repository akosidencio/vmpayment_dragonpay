<?php

defined('_JEXEC') or die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

/**
 *
 * 
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
			'status_canceled' => array('', 'char')
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
		
		//vcode
		$vcode = md5($totalInPaymentCurrency . $method->dragonpay_merchantid . $order['details']['BT']->order_number . $method->dragonpay_verifykey);
		
		//mart name
		$martquery = 'SELECT `vendor_store_name` FROM `#__virtuemart_vendors_en_gb` WHERE `virtuemart_vendor_id`="' . $method->virtuemart_vendor_id . '" ';
		$dbs = &JFactory::getDBO();
		$dbs->setQuery($martquery);
		$martname = $dbs->loadResult();
		
		//data need to send to dragonpay
		$post_variables = Array(
			'vcode' => $vcode,
			'bill_name' => $address->first_name." ".$address->last_name,
			'bill_email' => $address->email,
			'bill_mobile' => $address->phone_1,
			'country' => $country,
			'orderid' => $order['details']['BT']->order_number,
			"amount" => $totalInPaymentCurrency,
			"cur" => $currency_code_3,
			"bill_desc" => "Buy products from ".$martname.". Order No: ".$order['details']['BT']->order_number,
			"returnurl" => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id)
			);
				
		// Prepare data that should be stored in the database
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['payment_currency'] = $method->payment_currency;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency;
		$this->storePSPluginInternalData($dbValues);
		
		// add spin image
		$html.= '<form action="'.$method->dragonpay_merchantid.'/index.php" method="post" name="vm_dragonpay_form" >';
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
		$payment_data = JRequest::get('post');
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
		
		
				
		if (!class_exists('VirtueMartCart'))		{ require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');}
		if (!class_exists('shopFunctionsF'))		{ require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');}
		if (!class_exists('VirtueMartModelOrders'))	{ require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );}
	
		$virtuemart_order_id 	= VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		$payment_name 			= $this->renderPluginName($method);
	
		if( $skey != $key1 ) $status= -1; // invalid transaction
		
		//normal return
		if ($virtuemart_paymentmethod_ids)
		{
			if ( $status == "00" ) 
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
     * @author: Valerie Isaksen
     *
     * @param $cart_prices: cart prices
     * @param $payment
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
		// probably did not gave his BT:ST address
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

    /**
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {

	return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
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
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
	return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
	return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
	  $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     * @author Max Milbers

      public function plgVmOnCheckoutCheckDataPayment($psType, VirtueMartCart $cart) {
      return null;
      }
     */

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id) {
	return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * Save updated order data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     * @author Oscar van Eijk

      public function plgVmOnUpdateOrderPayment(  $_formData) {
      return null;
      }
     */
    /**
     * Save updated orderline data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     * @author Oscar van Eijk

      public function plgVmOnUpdateOrderLine(  $_formData) {
      return null;
      }
     */
    /**
     * plgVmOnEditOrderLineBE
     * This method is fired when editing the order line details in the backend.
     * It can be used to add line specific package codes
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     * @author Oscar van Eijk

      public function plgVmOnEditOrderLineBE(  $_orderId, $_lineId) {
      return null;
      }
     */

    /**
     * This method is fired when showing the order details in the frontend, for every orderline.
     * It can be used to display line specific package codes, e.g. with a link to external tracking and
     * tracing systems
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     * @author Oscar van Eijk

      public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
      return null;
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
