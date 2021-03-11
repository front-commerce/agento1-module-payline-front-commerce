<?php

/**
 * This helper contains the interactions duplicated and adapted
 * from the Payline module so that they can be used in a headless context
 */
class FrontCommerce_Payline_Helper_Duplication
{
  /**
   * Source: Monext_Payline_Helper_Widget::getDataToken()
   */
  public function getDataToken($quote)
  {
    try {
      $orderId = $this->_getReservedOrderId($quote);
      $quote->setRealOrderId($orderId);

      $array = Mage::helper('payline/payment')->initWithQuote($quote);
      $array['version'] = Monext_Payline_Helper_Data::VERSION;
      $array['payment']['action'] = Mage::getStoreConfig('payment/PaylineCPT/payline_payment_action');
      $array['payment']['mode'] = 'CPT';

      $returnUrl = Mage::getUrl('payline/index/cptReturnWidget');
      $array['payment']['contractNumber'] = $this->getDefaultContractNumberForWidget();
      $array['contracts'] = $this->getContractsForWidget(true);
      $array['secondContracts'] = $this->getContractsForWidget(false);
      if (empty($array['secondContracts'])) {
        $array['secondContracts'] = array('');
      }

      // if ($forShortcut) {
      //   $returnUrl = Mage::getUrl('payline/index/cptWidgetShortcut');
      //   $array['payment']['contractNumber'] = $this->getContractNumberForWidgetShortcut();
      //   $array['contracts'] = array($array['payment']['contractNumber']);
      //   //                    $array['secondContracts'] = array('');
      // }

      $fcBaseUrl = Mage::helper('frontcommerce_integration')
        ->getFrontCommerceUrl(Mage::app()->getStore());
      $fcBaseUrl = trim($fcBaseUrl, '/');

      $paylineSDK = Mage::helper('payline')->initPayline('CPT', $array['payment']['currency']);
      $paylineSDK->returnURL          = $fcBaseUrl . '/payline/process/widget';
      $paylineSDK->cancelURL          = $paylineSDK->returnURL;
      $paylineSDK->notificationURL    = $paylineSDK->returnURL;

      // WALLET
      // ADD CONTRACT WALLET ARRAY TO $array
      /** @var Monext_Payline_Helper_Data */
      $helperPayline = Mage::helper('payline');
      $array['walletContracts'] = $helperPayline->buildContractNumberWalletList();

      if (Mage::getStoreConfig('payment/PaylineCPT/send_wallet_id')) {

        if (!isset($array['buyer']['walletId'])) {
          if (isset($this->walletId)) {
            $array['buyer']['walletId'] = $this->walletId;
          }
        }

        $expiredWalletId = false;
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
          $customer = Mage::getSingleton('customer/session')->getCustomer();
          if ($customer->getWalletId() && !Mage::getModel('payline/wallet')->checkExpirationDate()) {
            $expiredWalletId = true;
          }
        }

        if ($expiredWalletId) {
          $this->walletId = null;
        }

        if ($helperPayline->canSubscribeWallet()) {
          // If the wallet is new (registered during payment), we must
          // save it in the private data since it's not sent back by
          // default
          if ($this->isNewWallet) {
            if ($this->walletId) {
              $paylineSDK->setPrivate(array(
                'key' => 'newWalletId',
                'value' => $this->walletId
              ));
            }
          }
        }
      }

      // echo json_encode($array);
      $response = $paylineSDK->doWebPayment($array);
      // echo json_encode($response);
      if (isset($response) and $response['result']['code'] == '00000' and !empty($response['token'])) {
        $token = $response['token'];
        Mage::getModel('payline/token')
          ->setOrderId($quote->getRealOrderId())
          ->setToken($token)
          ->setDateCreate(time())
          ->save();
        return $token;
      }
    } catch (Exception $e) {
      Mage::logException($e);
    }
  }

  /**
   * Source: Monext_Payline_IndexController::cptReturnWidgetAction()
   * and also parts of FrontCommerce_Integration_Model_Api2_Sales_Cart_Order::_placeOrder
   */
  public function placeOrderFromQuoteAndPaylineToken(
    $quote,
    $paylineToken,
    $customer = null
  ) {
    $quote->collectTotals()->save();
    $onePage = Mage::getSingleton('checkout/type_onepage')->setQuote($quote);
    try {
      if (empty($paylineToken)) {
        throw new Exception('No token');
      }

      $tokenData = Mage::getModel('payline/token')->load($paylineToken, 'token')->getData();

      // Order is loaded from id associated to the token
      if (sizeof($tokenData) == 0) {
        // TODO 400 HTTP error codes
        throw new Exception('No data for token ' . $paylineToken);
      }

      $contractNumber = Mage::helper('payline')->getDefaultContractNumberForWidget();
      if (empty($contractNumber)) {
        throw new Exception('Cannot find valid contract number');
      }

      $orderIncrementId = $tokenData['order_id'];

      $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);

      if (!$order->getId()) {
        if ($quote and $quote->getId() and $quote->getReservedOrderId() == $orderIncrementId) {

          $data = array('method' => 'PaylineCPT', 'cc_type' => $contractNumber);
          $quote->getPayment()->importData($data);

          // similar to the _placeOrder
          if ($customer) {
            Mage::getSingleton('customer/session')->setCustomer($customer);
          }

          $onePage->saveOrder();
          $onePage->getQuote()->save();

          Mage::helper('payline/logger')->log('[cptReturnWidgetAction] order ' . $orderIncrementId . ' created for quoteId:' . $quote->getId());
        } else {
          // Incorrect order_id
          throw new Exception('Quote getReservedOrderId (' . $quote->getReservedOrderId() . ') do not match tokenData (' . $orderIncrementId . ') for quoteId:' . $quote->getId());
        }
      } elseif (!$this->getRequest()->getParam('paylineshortcut')) {
        // Order should not be created exept from shortcut
        throw new Exception('Order already exist for ' . $orderIncrementId);
      }
    } catch (Exception $e) {
      //TODO: If payment is done it should be canceled.
      Mage::helper('payline/logger')->log('[cptReturnWidgetAction]  (' . $paylineToken . ') : Exception:  ' . $e->getMessage());
      $this->_critical($e->getMessage());
    }

    return [
      'quote_id' => $onePage->getQuote()->getId(),
      'order_id' => $onePage->getLastOrderId()
    ];
  }

  /**
   * Order increment ID getter (either real from order or a reserved from quote)
   *
   * @return string
   */
  protected function _getReservedOrderId($quote)
  {
    if (!$quote->getReservedOrderId()) {
      $quote->reserveOrderId()->save();
    }
    return $quote->getReservedOrderId();
  }

  private function getDefaultContractNumberForWidget()
  {
    $contracts = $this->getContractsForWidget();

    return !empty($contracts) ? $contracts[0] : false;
  }

  private function getContractsForWidget($primary = true)
  {
    $contractCPT = $this->getCcContracts(false, false, $primary);

    $contracts = array();
    foreach ($contractCPT as $contract) {
      $contracts[] = $contract->getNumber();
    }

    return $contracts;
  }

  /**
   * Return all contract with is_secure set to $securized.
   *
   * @param bool $securized
   *
   * @return Monext_Payline_Model_Mysql4_Contract_Collection
   */
  private function getCcContracts($securized = true, $cbType = true, $primary = true)
  {
    $key = ($securized) ? 1 : 0;
    $keyPrimary = ($primary) ? 1 : 0;
    if (!isset($this->_ccContracts[$key]) || !isset($this->_ccContracts[$key][$keyPrimary])) {

      $contracts = Mage::getModel('payline/contract')->getCollection()
        ->addFilterSecure($securized, Mage::app()->getStore()->getId());

      if ($cbType) {
        $contracts->addFieldToFilter('contract_type', array('CB', 'AMEX', 'MCVISA'));
      }

      //No need to be primary nor secondary if contract is secure
      if (!$securized) {
        $contracts->addFilterStatus($primary, Mage::app()->getStore()->getId());
      }

      $this->_ccContracts[$key][$keyPrimary] = $contracts;
    }

    return $this->_ccContracts[$key][$keyPrimary];
  }
}
