<?php

class FrontCommerce_Payline_Model_Api2_Payline_Token_Rest_Customer_V1
extends FrontCommerce_Integration_Model_Api2_Abstract
{
  const ACTION_TYPE_COLLECTION_ORDER = 'collectionOrder';

  protected function _retrieve()
  {
    $token = $this->payline()->getDataToken($this->_getQuote());
    // ToDo: error management

    return ['token' => $token];
  }

  protected function _placeOrder($params)
  {
    $result = $this->payline()->placeOrderFromQuoteAndPaylineToken(
      $this->_getQuote(),
      $params['token'],
      $this->_getCustomer()
    );
    // ToDo: error management

    // ToDo: do additional things like in cptReturn?
    // $this->_forward('cptReturn', NULL, NULL, array('paylinetoken' => $paylineToken));
    return $result;
  }

  /**
   * @return FrontCommerce_Payline_Helper_Duplication
   */
  private function payline()
  {
    return Mage::helper('frontcommerce_payline/duplication');
  }


  /**
   * Custom dispatch
   */
  public function dispatch()
  {
    $this->_initStore();
    switch ($this->getActionType() . $this->getOperation()) {
        // Generate payment token for Cart (doWebPaymentRequest))
      case self::ACTION_TYPE_ENTITY . self::OPERATION_RETRIEVE:
        $this->_errorIfMethodNotExist('_retrieve');
        $retrievedData = $this->_retrieve();
        $this->_render($retrievedData);
        break;

        // Place an order for Cart using the Payment token
      case self::ACTION_TYPE_COLLECTION_ORDER . self::OPERATION_CREATE:
        $requestData = $this->getRequest()->getBodyParams();
        $this->_errorIfMethodNotExist('_placeOrder');
        if (!isset($requestData['token'])) {
          $this->_critical(self::RESOURCE_REQUEST_DATA_INVALID);
        }
        $retrievedData = $this->_placeOrder($requestData);
        $this->_render($retrievedData);
        break;

      default:
        $this->_critical(self::RESOURCE_METHOD_NOT_IMPLEMENTED);
        break;
    }
  }


  /// DUPLICATED FROM FC
  //  ToDo: find a clean way to allow reuse of the code below


  /**
   * Init quote
   *
   * @param null $quoteIdEncrypt
   * @return Mage_Sales_Model_Quote
   *
   * ToDo: from src/.modman/magento1-module/app/code/community/FrontCommerce/Integration/Model/Api2/Sales/Cart/Rest/Customer/V1.php
   */
  protected function _getQuote($quoteIdEncrypt = null)
  {
    if (!$this->_quote) {
      $this->_getCustomer();
      $this->_quote    = Mage::getModel('sales/quote')->setStore($this->_getStore())->loadByCustomer($this->_customer);
      if (!$this->_quote || !$this->_quote->getId()) {
        $this->_critical(self::RESOURCE_REQUEST_DATA_INVALID, null, false);
      }
      Mage::getSingleton('checkout/cart')->setQuote($this->_quote);
    }
    $this->_updateQuoteCurrency();
    return $this->_quote;
  }


  /**
   * ToDo: from src/.modman/magento1-module/app/code/community/FrontCommerce/Integration/Model/Api2/Sales/Cart/Rest/Customer/V1.php
   */
  protected function _getCustomer()
  {
    if (!$this->_customer) {
      if (!$customerId = $this->getApiUser()->getUserId()) {
        $this->_critical(self::RESOURCE_NOT_FOUND);
      }
      $customer      = Mage::getModel('customer/customer')->load($customerId);
      if (!$customer || !$customer->getId()) {
        $this->_critical(self::RESOURCE_REQUEST_DATA_INVALID);
      }
      $this->_customer = $customer;
    }
    return $this->_customer;
  }

  /**
   * Force to update quote currency if user switch to other currency than
   * base quote currency
   *
   * ToDo: from FrontCommerce/Integration/Model/Api2/Sales/Cart/Cart.php
   */
  protected function _updateQuoteCurrency()
  {
    if (!$this->_quote || !$this->_currency || $this->_quote->getQuoteCurrencyCode() === $this->_currency) {
      return false;
    }
    $currentCurrency = Mage::getModel('directory/currency')->load($this->_currency);
    if (!$currentCurrency) {
      return false;
    }

    $this->_quote
      ->setForcedCurrency($currentCurrency)
      ->collectTotals()
      ->save();
    /*
         * We mast to create new quote object, because collectTotals()
         * can to create links with other objects.
         */
    $this->_quote = Mage::getModel('sales/quote')
      ->setStoreId($this->_getStore()->getId())
      ->load($this->_quote->getId());
  }
}
