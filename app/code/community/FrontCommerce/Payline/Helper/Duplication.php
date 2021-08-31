<?php

/**
 * This helper contains the interactions duplicated and adapted
 * from the Payline module so that they can be used in a headless context
 */
class FrontCommerce_Payline_Helper_Duplication
{
  const STATUS_SUCCESS = "success";
  const STATUS_PENDING = "pending";
  const STATUS_ERROR = "error";

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

      $fcBaseUrl = $this->__getStoreUrl();

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
          if (isset($helperPayline->walletId)) {
            $array['buyer']['walletId'] = $helperPayline->walletId;
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
          $helperPayline->walletId = null;
        }

        if ($helperPayline->canSubscribeWallet()) {
          // If the wallet is new (registered during payment), we must
          // save it in the private data since it's not sent back by
          // default
          if ($helperPayline->isNewWallet) {
            if ($helperPayline->walletId) {
              $paylineSDK->setPrivate(array(
                'key' => 'newWalletId',
                'value' => $helperPayline->walletId
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

  private function __getStoreUrl()
  {
    $fcBaseUrl = Mage::app()->getStore()->getUrl();
    return trim($fcBaseUrl, '/');
  }

  /**
   * Source: Monext_Payline_IndexController::cptReturnAction()
   * and also parts of FrontCommerce_Integration_Model_Api2_Sales_Cart_Order::_placeOrder
   */
  public function placeOrderFromPaylineToken(
    $paylineToken,
    $customer = null
  ) {
    $tokenData = Mage::getModel('payline/token')->load($paylineToken, 'token')->getData();

    // Order is loaded from id associated to the token
    if (sizeof($tokenData) == 0) {
      Mage::helper('payline/logger')->log('[cptReturnAction] - token ' . $paylineToken . ' is unknown');
      throw new \RuntimeException('Token ' . $paylineToken . ' is unknown');
      return;
    }
    $this->order = Mage::getModel('sales/order')->loadByIncrementId($tokenData['order_id']);

    if (!in_array($tokenData['status'], array(0, 3))) { // order update is already done => exit this function
      $acceptedCodes = array(
        '00000', // Credit card -> Transaction approved
        '02500', // Wallet -> Operation successfull
        '02501', // Wallet -> Operation Successfull with warning / Operation Successfull but wallet will expire
        '04003', // Fraud detected - BUT Transaction approved (04002 is Fraud with payment refused)
        '00100',
        '03000',
        '34230', // signature SDD
        '34330' // prélèvement SDD
      );

      if (in_array($tokenData['result_code'], $acceptedCodes)) {
        return [
          'order_id' => $this->order->getIncrementId(),
          'result_code' => $tokenData['result_code']
        ];
      } else {
        throw new \RuntimeException(Mage::helper('payline')->__('Your payment is refused'));
      }
    }

    $tokenForUpdate = Mage::getModel('payline/token')->load($tokenData['id']);
    $webPaymentDetails = Mage::helper('payline')->initPayline('CPT')->getWebPaymentDetails(array('token' => $paylineToken, 'version' => Monext_Payline_Helper_Data::VERSION));

    $this->order->getPayment()->setAdditionalInformation('payline_payment_info', $webPaymentDetails['payment']);

    $paymentStatus = static::STATUS_ERROR;
    $userMessage = null;
    if (isset($webPaymentDetails)) {
      if (is_array($webPaymentDetails) and !empty($webPaymentDetails['transaction'])) {
        if (!empty($webPaymentDetails['transaction']['id']) && Mage::helper('payline/payment')->updateOrder($this->order, $webPaymentDetails, $webPaymentDetails['transaction']['id'], 'CPT')) {
          $paymentStatus = static::STATUS_SUCCESS;

          // set order status
          if ($webPaymentDetails['result']['code'] == '04003') {
            // we consider that a pending risk related to the LCLF module with anti-fraud rules should still be marked as pending…
            // even though the transaction was accepted
            $paymentStatus = static::STATUS_PENDING;
            $newOrderStatus = Mage::getStoreConfig('payment/payline_common/fraud_order_status');
            Mage::helper('payline')->setOrderStatus($this->order, $newOrderStatus);
          } else {
            Mage::helper('payline')->setOrderStatusAccordingToPaymentMode(
              $this->order,
              $webPaymentDetails['payment']['action']
            );
          }

          // update token model to flag this order as already updated and save resultCode & transactionId
          $tokenForUpdate->setStatus(1); // OK
          $tokenForUpdate->setTransactionId($webPaymentDetails['transaction']['id']);
          $tokenForUpdate->setResultCode($webPaymentDetails['result']['code']);

          // save wallet if created during this payment
          if (!empty($webPaymentDetails['wallet']) and !empty($webPaymentDetails['wallet']['walletId'])) {
            $this->saveWallet($customer, $webPaymentDetails['wallet']['walletId']);
          } elseif (!empty($webPaymentDetails['privateDataList']) and !empty($webPaymentDetails['privateDataList']['privateData'])) {
            $privateDataList = $webPaymentDetails['privateDataList']['privateData'];
            if (!empty($privateDataList['key']) and !empty($privateDataList['value']) and $privateDataList['key'] == 'newWalletId') {
              $this->saveWallet($customer, $privateDataList['value']);
            } else {
              foreach ($webPaymentDetails['privateDataList']['privateData'] as $privateDataList) {
                if (is_object($privateDataList) && $privateDataList->key == 'newWalletId') {
                  if (isset($webPaymentDetails['wallet']) && $webPaymentDetails['wallet']['walletId'] == $privateDataList->value) { // Customer may have unchecked the "Save this information for my next orders" checkbox on payment page. If so, wallet is not created !
                    $this->saveWallet($customer, $privateDataList->value);
                  }
                }
              }
            }
          }

          // create invoice if needed
          Mage::helper('payline')->automateCreateInvoiceAtShopReturn('CPT', $this->order);
        } else {
          // payment NOT OK
          $msgLog = 'PAYMENT KO : ' . $webPaymentDetails['result']['code'] . ' ' . $webPaymentDetails['result']['shortMessage'] . ' (' . $webPaymentDetails['result']['longMessage'] . ')';
          $tokenForUpdate->setResultCode($webPaymentDetails['result']['code']);

          $pendingCodes = array(
            '02306', // Customer has to fill his payment data
            '02533', // Customer not redirected to payment page AND session is active
            '02000', // transaction in progress
            '02005', // transaction in progress
            '02015', // transaction delegated to partner
            '02016', // Transaction pending by the partner
            '02017', // Transaction pending
            paylineSDK::ERR_CODE // communication issue between Payline and the store
          );

          if (!in_array($webPaymentDetails['result']['code'], $pendingCodes)) {
            if ($webPaymentDetails['result']['code'] == '00000') { // payment is OK, but a mismatch with order was detected
              $paymentDataMismatchStatus = Mage::getStoreConfig('payment/payline_common/payment_mismatch_order_status');
              $this->order->setState(Mage_Sales_Model_Order::STATE_CANCELED, $paymentDataMismatchStatus, $msgLog, false);
            } elseif (in_array($webPaymentDetails['result']['code'], array('02304', '02324', '02534'))) {
              $abandonedStatus = Mage::getStoreConfig('payment/payline_common/resignation_order_status');
              $this->order->setState(Mage_Sales_Model_Order::STATE_CANCELED, $abandonedStatus, $msgLog, false);
            } elseif ($webPaymentDetails['result']['code'] == '02319') {
              $userMessage = Mage::helper('payline')->__('Your payment is canceled');
              $canceledStatus = Mage::getStoreConfig('payment/payline_common/canceled_order_status');
              $this->order->setState(Mage_Sales_Model_Order::STATE_CANCELED, $canceledStatus, $msgLog, false);
            } else {
              $userMessage = Mage::helper('payline')->__('Your payment is refused');
              $failedOrderStatus = Mage::getStoreConfig('payment/payline_common/failed_order_status');
              $this->order->setState(Mage_Sales_Model_Order::STATE_CANCELED, $failedOrderStatus, $msgLog, false);
            }
            $tokenForUpdate->setStatus(2); // KO
            $paymentStatus = static::STATUS_ERROR;
          } else {
            $userMessage = Mage::helper('payline')->__('Your payment is saved');
            $waitOrderStatus = Mage::getStoreConfig('payment/payline_common/wait_order_status');
            $this->order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, $waitOrderStatus, $msgLog, false);
            $tokenForUpdate->setStatus(3); // to be determined
            $paymentStatus = static::STATUS_PENDING;
          }
          Mage::helper('payline/logger')->log('[cptReturnAction] ' . $this->order->getIncrementId() . ' ' . $msgLog);
        }
        $tokenForUpdate->setDateUpdate(date('Y-m-d G:i:s'));
        $tokenForUpdate->save();
      } elseif (is_string($webPaymentDetails)) {
        $paymentStatus = static::STATUS_ERROR;
        Mage::helper('payline/logger')->log('[cptReturnAction] order ' . $this->order->getIncrementId() . ' - ERROR - ' . $webPaymentDetails);
      } else {
        $paymentStatus = static::STATUS_ERROR;
      }
    } else {
      Mage::helper('payline/logger')->log('[cptReturnAction] order ' . $this->order->getIncrementId() . ' : unknown error during update');
      throw new \RuntimeException($this->order->getIncrementId() . ': unknown error during update');
    }

    $this->order->save();
    return [
      'order_id' => $this->order->getIncrementId(),
      'result_code' => $webPaymentDetails['result']['code'],
      'transaction_id' => $webPaymentDetails['transaction']['id'],
      'status' => $paymentStatus,
      'message' => $userMessage,
    ];
  }

  /**
   * Check if the customer is logged, and if it has a wallet
   * If not & if there is a walletId in the result from Payline, we save it
   */
  private function saveWallet($customer, $walletId)
  {
    if (!Mage::getStoreConfig('payment/payline_common/automate_wallet_subscription')) {
      return;
    }
    // ToDo: handle guest checkout
    // if ($customerSession->isLoggedIn()) {
    if (!$customer->getWalletId()) {
      $customer->setWalletId($walletId);
      $customer->save();
    }
  }

  /**
   * Source: Monext_Payline_IndexController::cptReturnWidgetAction()
   * and also parts of FrontCommerce_Integration_Model_Api2_Sales_Cart_Order::_placeOrder
   */
  public function setPaylinePaymentTokenAndCreateOrder(
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
      } else {
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
