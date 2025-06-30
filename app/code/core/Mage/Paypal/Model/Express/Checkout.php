<?php

/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magento.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magento.com for more information.
 *
 * @category    Mage
 * @package     Mage_Paypal
 * @copyright  Copyright (c) 2006-2020 Magento, Inc. (http://www.magento.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Wrapper that performs Paypal Express and Checkout communication
 * Use current Paypal Express method instance
 */
class Mage_Paypal_Model_Express_Checkout
{
    /**
     * Cache ID prefix for "pal" lookup
     * @var string
     */
    const PAL_CACHE_ID = 'paypal_express_checkout_pal';

    /**
     * Keys for passthrough variables in sales/quote_payment and sales/order_payment
     * Uses additional_information as storage
     * @var string
     */
    const PAYMENT_INFO_TRANSPORT_TOKEN    = 'paypal_express_checkout_token';
    const PAYMENT_INFO_TRANSPORT_SHIPPING_OVERRIDEN = 'paypal_express_checkout_shipping_overriden';
    const PAYMENT_INFO_TRANSPORT_SHIPPING_METHOD = 'paypal_express_checkout_shipping_method';
    const PAYMENT_INFO_TRANSPORT_PAYER_ID = 'paypal_express_checkout_payer_id';
    const PAYMENT_INFO_TRANSPORT_REDIRECT = 'paypal_express_checkout_redirect_required';
    const PAYMENT_INFO_TRANSPORT_BILLING_AGREEMENT = 'paypal_ec_create_ba';

    /**
     * Flag which says that was used PayPal Express Checkout button for checkout
     * Uses additional_information as storage
     * @var string
     */
    const PAYMENT_INFO_BUTTON = 'button';

    /**
     * @var Mage_Sales_Model_Quote
     */
    protected $_quote = null;

    /**
     * Config instance
     * @var Mage_Paypal_Model_Config
     */
    protected $_config = null;

    /**
     * API instance
     * @var Mage_Paypal_Model_Api_Nvp
     */
    protected $_api = null;

    /**
     * Api Model Type
     *
     * @var string
     */
    protected $_apiType = 'paypal/api_nvp';

    /**
     * Payment method type
     *
     * @var string
     */
    protected $_methodType = Mage_Paypal_Model_Config::METHOD_WPP_EXPRESS;

    /**
     * State helper variables
     * @var string
     */
    protected $_redirectUrl = '';
    protected $_pendingPaymentMessage = '';
    protected $_checkoutRedirectUrl = '';

    /**
     * @var Mage_Customer_Model_Session
     */
    protected $_customerSession;

    /**
     * Redirect urls supposed to be set to support giropay
     *
     * @var array
     */
    protected $_giropayUrls = array();

    /**
     * Create Billing Agreement flag
     *
     * @var bool
     */
    protected $_isBARequested = false;

    /**
     * Flag for Bill Me Later mode
     *
     * @var bool
     */
    protected $_isBml = false;

    /**
     * Customer ID
     *
     * @var int
     */
    protected $_customerId = null;

    /**
     * Recurring payment profiles
     *
     * @var array
     */
    protected $_recurringPaymentProfiles = array();

    /**
     * Billing agreement that might be created during order placing
     *
     * @var Mage_Sales_Model_Billing_Agreement
     */
    protected $_billingAgreement = null;

    /**
     * Order
     *
     * @var Mage_Sales_Model_QuoteMage_Sales_Model_Quote
     */
    protected $_order = null;

    /**
     * Set quote and config instances
     * @param array $params
     */
    public function __construct($params = array())
    {
        if (isset($params['quote']) && $params['quote'] instanceof Mage_Sales_Model_Quote) {
            $this->_quote = $params['quote'];
        } else {
            throw new Exception('Quote instance is required.');
        }
        if (isset($params['config']) && $params['config'] instanceof Mage_Paypal_Model_Config) {
            $this->_config = $params['config'];
        } else {
            throw new Exception('Config instance is required.');
        }
        $this->_customerSession = isset($params['session']) && $params['session'] instanceof Mage_Customer_Model_Session
            ? $params['session'] : Mage::getSingleton('customer/session');
    }

    /**
     * Checkout with PayPal image URL getter
     * Spares API calls of getting "pal" variable, by putting it into cache per store view
     * @return string
     */
    public function getCheckoutShortcutImageUrl()
    {
        // get "pal" thing from cache or lookup it via API
        $pal = null;
        if ($this->_config->areButtonsDynamic()) {
            $cacheId = self::PAL_CACHE_ID . Mage::app()->getStore()->getId();
            $pal = Mage::app()->loadCache($cacheId);
            if (-1 == $pal) {
                $pal = null;
            } elseif (!$pal) {
                $pal = null;
                $this->_getApi();
                try {
                    $this->_api->callGetPalDetails();
                    $pal = $this->_api->getPal();
                    Mage::app()->saveCache($pal, $cacheId, array(Mage_Core_Model_Config::CACHE_TAG));
                } catch (Exception $e) {
                    Mage::app()->saveCache(-1, $cacheId, array(Mage_Core_Model_Config::CACHE_TAG));
                    Mage::logException($e);
                }
            }
        }

        return $this->_config->getExpressCheckoutShortcutImageUrl(
            Mage::app()->getLocale()->getLocaleCode(),
            $this->_quote->getBaseGrandTotal(),
            $pal
        );
    }

    /**
     * Setter that enables giropay redirects flow
     *
     * @param string $successUrl - payment success result
     * @param string $cancelUrl  - payment cancellation result
     * @param string $pendingUrl - pending payment result
     * @return $this
     */
    public function prepareGiropayUrls($successUrl, $cancelUrl, $pendingUrl)
    {
        $this->_giropayUrls = array($successUrl, $cancelUrl, $pendingUrl);
        return $this;
    }

    /**
     * Set create billing agreement flag
     *
     * @param bool $flag
     * @return $this
     */
    public function setIsBillingAgreementRequested($flag)
    {
        $this->_isBARequested = $flag;
        return $this;
    }

    /**
     * Setter for customer Id
     *
     * @param int $id
     * @return $this
     * @deprecated please use self::setCustomer
     */
    public function setCustomerId($id)
    {
        $this->_customerId = $id;
        return $this;
    }

    /**
     * Set flag that forces to use BillMeLater
     *
     * @param bool $isBml
     */
    public function setIsBml($isBml)
    {
        $this->_isBml = $isBml;
    }

    /**
     * Setter for customer
     *
     * @param Mage_Customer_Model_Customer $customer
     * @return $this
     */
    public function setCustomer($customer)
    {
        $this->_quote->assignCustomer($customer);
        $this->_customerId = $customer->getId();
        return $this;
    }

    /**
     * Setter for customer with billing and shipping address changing ability
     *
     * @param  Mage_Customer_Model_Customer   $customer
     * @param  Mage_Sales_Model_Quote_Address $billingAddress
     * @param  Mage_Sales_Model_Quote_Address $shippingAddress
     * @return $this
     */
    public function setCustomerWithAddressChange($customer, $billingAddress = null, $shippingAddress = null)
    {
        $this->_quote->assignCustomerWithAddressChange($customer, $billingAddress, $shippingAddress);
        $this->_customerId = $customer->getId();
        return $this;
    }

    /**
     * Reserve order ID for specified quote and start checkout on PayPal
     *
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param bool|null $button
     * @return mixed
     */
    public function start($returnUrl, $cancelUrl, $button = null)
    {
        $this->_quote->collectTotals();

        if (!$this->_quote->getGrandTotal() && !$this->_quote->hasNominalItems()) {
            Mage::throwException(Mage::helper('paypal')->__('PayPal does not support processing orders with zero amount. To complete your purchase, proceed to the standard checkout process.'));
        }

        $this->_quote->reserveOrderId()->save();
        // prepare API
        $this->_getApi();
        $solutionType = $this->_config->getMerchantCountry() == 'DE'
            ? Mage_Paypal_Model_Config::EC_SOLUTION_TYPE_MARK : $this->_config->solutionType;
        $this->_api->setAmount($this->_quote->getBaseGrandTotal())
            ->setCurrencyCode($this->_quote->getBaseCurrencyCode())
            ->setInvNum($this->_quote->getReservedOrderId())
            ->setReturnUrl($returnUrl)
            ->setCancelUrl($cancelUrl)
            ->setSolutionType($solutionType)
            ->setPaymentAction($this->_config->paymentAction);

        if ($this->_giropayUrls) {
            list($successUrl, $cancelUrl, $pendingUrl) = $this->_giropayUrls;
            $this->_api->addData(array(
                'giropay_cancel_url' => $cancelUrl,
                'giropay_success_url' => $successUrl,
                'giropay_bank_txn_pending_url' => $pendingUrl,
            ));
        }

        if ($this->_isBml) {
            $this->_api->setFundingSource('BML');
        }

        $this->_setBillingAgreementRequest();

        if ($this->_config->requireBillingAddress == Mage_Paypal_Model_Config::REQUIRE_BILLING_ADDRESS_ALL) {
            $this->_api->setRequireBillingAddress(1);
        }

        // supress or export shipping address
        if ($this->_quote->getIsVirtual()) {
            if ($this->_config->requireBillingAddress == Mage_Paypal_Model_Config::REQUIRE_BILLING_ADDRESS_VIRTUAL) {
                $this->_api->setRequireBillingAddress(1);
            }
            $this->_api->setSuppressShipping(true);
        } else {
            $address = $this->_quote->getShippingAddress();
            $isOverriden = 0;
            if (true === $address->validate()) {
                $isOverriden = 1;
                $this->_api->setAddress($address);
            }
            $this->_quote->getPayment()->setAdditionalInformation(
                self::PAYMENT_INFO_TRANSPORT_SHIPPING_OVERRIDEN, $isOverriden
            );
            $this->_quote->getPayment()->save();
        }

        // add line items
        $paypalCart = Mage::getModel('paypal/cart', array($this->_quote));
        $this->_api->setPaypalCart($paypalCart)
            ->setIsLineItemsEnabled($this->_config->lineItemsEnabled)
        ;

        // add shipping options if needed and line items are available
        Mage::log("PayPal Express Debug: lineItemsEnabled=" . ($this->_config->lineItemsEnabled ? 'true' : 'false') . 
                 ", transferShippingOptions=" . ($this->_config->transferShippingOptions ? 'true' : 'false') . 
                 ", paypalCartItems=" . count($paypalCart->getItems()) . 
                 ", isVirtual=" . ($this->_quote->getIsVirtual() ? 'true' : 'false') . 
                 ", hasNominalItems=" . ($this->_quote->hasNominalItems() ? 'true' : 'false'), 
                 null, 'paypal_shipping.log');
        
        if ($this->_config->lineItemsEnabled && $this->_config->transferShippingOptions && $paypalCart->getItems()) {
            if (!$this->_quote->getIsVirtual() && !$this->_quote->hasNominalItems()) {
                // Create shipping method mapping for all available shipping methods
                $this->_createShippingMethodMapping();
                
                // Provide a single placeholder option to enable callbacks
                // Must be default to satisfy PayPal's requirement, callbacks will provide real options
                $placeholderOption = new Varien_Object(array(
                    'is_default' => true, // Required by PayPal
                    'name'       => '', // Empty to minimize display
                    'code'       => 'callback_placeholder',
                    'amount'     => 0.00,
                ));
                
                $callbackUrl = Mage::getUrl('paypal/express/shippingOptionsCallback', array(
                    'quote_id' => $this->_quote->getId(),
                    '_secure' => true
                ));
                
                Mage::log("PayPal Express: Setting callback URL: " . $callbackUrl, null, 'paypal_shipping.log');
                Mage::log("PayPal Express: Quote ID: " . $this->_quote->getId(), null, 'paypal_shipping.log');
                
                $this->_api->setShippingOptionsCallbackUrl($callbackUrl)
                          ->setShippingOptions(array($placeholderOption));
                
                // Verify what was actually set
                if (method_exists($this->_api, 'getShippingOptionsCallbackUrl')) {
                    $verifyUrl = $this->_api->getShippingOptionsCallbackUrl();
                    Mage::log("PayPal Express: Verified callback URL in API: " . $verifyUrl, null, 'paypal_shipping.log');
                } else {
                    Mage::log("PayPal Express: Cannot verify callback URL - getter method not available", null, 'paypal_shipping.log');
                }
                
                Mage::log("PayPal Express: Using placeholder option to force callback usage", 
                         null, 'paypal_shipping.log');
            } else {
                Mage::log("PayPal Express: Skipped shipping options - virtual quote or nominal items", 
                         null, 'paypal_shipping.log');
            }
        } else {
            Mage::log("PayPal Express: Shipping options not configured - check conditions above", 
                     null, 'paypal_shipping.log');
        }

        // add recurring payment profiles information
        if ($profiles = $this->_quote->prepareRecurringPaymentProfiles()) {
            foreach ($profiles as $profile) {
                $profile->setMethodCode(Mage_Paypal_Model_Config::METHOD_WPP_EXPRESS);
                if (!$profile->isValid()) {
                    Mage::throwException($profile->getValidationErrors(true, true));
                }
            }
            $this->_api->addRecurringPaymentProfiles($profiles);
        }

        $this->_config->exportExpressCheckoutStyleSettings($this->_api);

        // call API and redirect with token
        $this->_api->callSetExpressCheckout();
        $token = $this->_api->getToken();
        $this->_redirectUrl = $button ? $this->_config->getExpressCheckoutStartUrl($token)
            : $this->_config->getPayPalBasicStartUrl($token);

        $this->_quote->getPayment()->unsAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_BILLING_AGREEMENT);

        // Set flag that we came from Express Checkout button
        if (!empty($button)) {
            $this->_quote->getPayment()->setAdditionalInformation(self::PAYMENT_INFO_BUTTON, 1);
        } elseif ($this->_quote->getPayment()->hasAdditionalInformation(self::PAYMENT_INFO_BUTTON)) {
            $this->_quote->getPayment()->unsAdditionalInformation(self::PAYMENT_INFO_BUTTON);
        }

        $this->_quote->getPayment()->save();
        return $token;
    }

    /**
     * Check whether system can skip order review page before placing order
     *
     * @return bool
     */
    public function canSkipOrderReviewStep()
    {
        $isOnepageCheckout = !$this->_quote->getPayment()
            ->getAdditionalInformation(Mage_Paypal_Model_Express_Checkout::PAYMENT_INFO_BUTTON);
        return $this->_config->isOrderReviewStepDisabled() && $isOnepageCheckout;
    }

    /**
     * Update quote when returned from PayPal
     * rewrite billing address by paypal
     * save old billing address for new customer
     * export shipping address in case address absence
     *
     * @param string $token
     */
    public function returnFromPaypal($token)
    {
        $this->_getApi();
        $this->_api->setToken($token)
            ->callGetExpressCheckoutDetails();
        $quote = $this->_quote;

        $this->_ignoreAddressValidation();

        // import shipping address
        $exportedShippingAddress = $this->_api->getExportedShippingAddress();
        
        
        if (!$quote->getIsVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            if ($shippingAddress) {
                if ($exportedShippingAddress) {
                    
                    $this->_setExportedAddressData($shippingAddress, $exportedShippingAddress);

                    // Always fix name duplication for PayPal Express - regardless of button flag
                    
                    // Fix name duplication - PayPal may send full name in firstname field
                    $firstname = trim($shippingAddress->getFirstname());
                    $lastname = trim($shippingAddress->getLastname());
                    
                    // If firstname contains the full name and lastname is just the last part, split it properly
                    if ($firstname && $lastname && strpos($firstname, $lastname) !== false && $firstname !== $lastname) {
                        // Firstname contains lastname, split properly
                        $nameParts = explode(' ', $firstname, 2);
                        $shippingAddress->setFirstname($nameParts[0]);
                        $shippingAddress->setLastname(isset($nameParts[1]) ? $nameParts[1] : $lastname);
                        
                        Mage::log("PayPal Express: Fixed shipping name duplication", null, 'paypal_shipping.log');
                    }
                    
                    if ($quote->getPayment()->getAdditionalInformation(self::PAYMENT_INFO_BUTTON) == 1) {
                        $shippingAddress->setPrefix(null);
                        $shippingAddress->setMiddlename(null);
                        $shippingAddress->setSuffix(null);
                    }

                    $shippingAddress->setCollectShippingRates(true);
                    $shippingAddress->setSameAsBilling(0);
                }

                // import shipping method
                $code = '';
                if ($this->_api->getShippingRateCode()) {
                    $shippingRateCode = $this->_api->getShippingRateCode();
                    
                    Mage::log("PayPal Express: returnFromPaypal - received shipping rate code: '{$shippingRateCode}'", 
                             null, 'paypal_shipping.log');
                    
                    // Map clean title back to original shipping method code
                    $originalCode = $this->_getOriginalShippingMethodCode($shippingRateCode);
                    if ($originalCode) {
                        $shippingRateCode = $originalCode;
                        Mage::log("PayPal Express: Mapped shipping method '{$this->_api->getShippingRateCode()}' back to '{$originalCode}'", 
                                 null, 'paypal_shipping.log');
                    } else {
                        Mage::log("PayPal Express: No mapping found for '{$shippingRateCode}', using as-is", 
                                 null, 'paypal_shipping.log');
                    }
                    
                    Mage::log("PayPal Express: Attempting to set shipping method code: '{$shippingRateCode}'", 
                             null, 'paypal_shipping.log');
                    
                    // Since we have the correct shipping method code from mapping, set it directly
                    if ($shippingRateCode && $shippingRateCode !== 'tbd_shipping') {
                        $shippingAddress->setShippingMethod($shippingRateCode);
                        $code = $shippingRateCode;
                        Mage::log("PayPal Express: Successfully set shipping method: '{$code}'", 
                                 null, 'paypal_shipping.log');
                    } else {
                        // Only try matching if we don't have a valid mapped code
                        if ($code = $this->_matchShippingMethodCode($shippingAddress, $shippingRateCode)) {
                            $shippingAddress->setShippingMethod($code)->setCollectShippingRates(true);
                            Mage::log("PayPal Express: Matched and set shipping method: '{$code}'", 
                                     null, 'paypal_shipping.log');
                        } else {
                            Mage::log("PayPal Express: FAILED to match shipping method code: '{$shippingRateCode}'", 
                                     null, 'paypal_shipping.log');
                        }
                    }
                } else {
                    Mage::log("PayPal Express: No shipping rate code received from PayPal", 
                             null, 'paypal_shipping.log');
                }
                $quote->getPayment()->setAdditionalInformation(
                    self::PAYMENT_INFO_TRANSPORT_SHIPPING_METHOD,
                    $code
                );
            }
        }

        // import billing address
        $portBillingFromShipping = $quote->getPayment()->getAdditionalInformation(self::PAYMENT_INFO_BUTTON) == 1
            && $this->_config->requireBillingAddress != Mage_Paypal_Model_Config::REQUIRE_BILLING_ADDRESS_ALL
            && !$quote->isVirtual();
        if ($portBillingFromShipping) {
            $billingAddress = clone $shippingAddress;
            $billingAddress->unsAddressId()
                ->unsAddressType();
            $data = $billingAddress->getData();
            $data['save_in_address_book'] = 0;
            $quote->getBillingAddress()->addData($data);
            $quote->getShippingAddress()->setSameAsBilling(1);
        } else {
            $billingAddress = $quote->getBillingAddress();
        }
        $exportedBillingAddress = $this->_api->getExportedBillingAddress();
        
        
        $this->_setExportedAddressData($billingAddress, $exportedBillingAddress);
        
        // Fix billing address for PayPal Express
        // Debug: Log the billing address data before processing
        Mage::log("PayPal Express: Billing address before fix - " .
                 "firstname: '{$billingAddress->getFirstname()}', " .
                 "lastname: '{$billingAddress->getLastname()}', " .
                 "street: '{$billingAddress->getStreet1()}', " .
                 "city: '{$billingAddress->getCity()}'", 
                 null, 'paypal_shipping.log');
        
        // If billing address is incomplete (missing street/city), copy from shipping
        if (empty($billingAddress->getStreet1()) && empty($billingAddress->getCity()) && !$quote->getIsVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            if ($shippingAddress && $shippingAddress->getStreet1()) {
                // Copy address details from shipping to billing
                $billingAddress->setStreet($shippingAddress->getStreet())
                              ->setCity($shippingAddress->getCity())
                              ->setRegion($shippingAddress->getRegion())
                              ->setRegionId($shippingAddress->getRegionId())
                              ->setPostcode($shippingAddress->getPostcode())
                              ->setCountryId($shippingAddress->getCountryId());
                
                Mage::log("PayPal Express: Copied address details from shipping to billing", 
                         null, 'paypal_shipping.log');
            }
        }
        
        if ($quote->getPayment()->getAdditionalInformation(self::PAYMENT_INFO_BUTTON) == 1) {
            $billingAddress->setPrefix(null);
            $billingAddress->setMiddlename(null);
            $billingAddress->setSuffix(null);
        }
        
        $billingAddress->setCustomerNotes($exportedBillingAddress->getData('note'));
        $quote->setBillingAddress($billingAddress);

        // Set customer name from billing address for PayPal Express orders
        if ($quote->getCustomerIsGuest()) {
            $customerFirstname = $billingAddress->getFirstname();
            $customerLastname = $billingAddress->getLastname();
            
            if ($customerFirstname || $customerLastname) {
                $quote->setCustomerFirstname($customerFirstname)
                      ->setCustomerLastname($customerLastname);
                
                Mage::log("PayPal Express: Set customer name to '{$customerFirstname} {$customerLastname}'", 
                         null, 'paypal_shipping.log');
            }
        }

        // import payment info
        $payment = $quote->getPayment();
        $payment->setMethod($this->_methodType);
        Mage::getSingleton('paypal/info')->importToPayment($this->_api, $payment);
        $payment->setAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_PAYER_ID, $this->_api->getPayerId())
            ->setAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_TOKEN, $token)
        ;
        $quote->collectTotals()->save();
    }

    /**
     * Check whether order review has enough data to initialize
     *
     * @param $token
     * @throws Mage_Core_Exception
     */
    public function prepareOrderReview($token = null)
    {
        $payment = $this->_quote->getPayment();
        if (!$payment || !$payment->getAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_PAYER_ID)) {
            Mage::throwException(Mage::helper('paypal')->__('Payer is not identified.'));
        }
        $this->_quote->setMayEditShippingAddress(
            1 != $this->_quote->getPayment()->getAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_SHIPPING_OVERRIDEN)
        );
        $this->_quote->setMayEditShippingMethod(
            '' == $this->_quote->getPayment()->getAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_SHIPPING_METHOD)
        );
        $this->_ignoreAddressValidation();
        $this->_quote->collectTotals()->save();
    }

    /**
     * Return callback response with shipping options
     *
     * @param array $request
     * @return string
     */
    public function getShippingOptionsCallbackResponse(array $request)
    {
        Mage::log("PayPal Express: Callback received - Request: " . print_r($request, true), null, 'paypal_shipping.log');
        
        // prepare debug data
        $logger = Mage::getModel('core/log_adapter', 'payment_' . $this->_methodType . '.log');
        $debugData = array('request' => $request, 'response' => array());

        try {
            // obtain addresses
            $this->_getApi();
            $address = $this->_api->prepareShippingOptionsCallbackAddress($request);
            $quoteAddress = $this->_quote->getShippingAddress();
            
            Mage::log("PayPal Express: Callback address prepared - Country: " . ($address ? $address->getCountryId() : 'none') . 
                     ", Postcode: " . ($address ? $address->getPostcode() : 'none'), null, 'paypal_shipping.log');

            // compare addresses, calculate shipping rates and prepare response
            $options = array();
            if ($address && $quoteAddress && !$this->_quote->getIsVirtual()) {
                foreach ($address->getExportedKeys() as $key) {
                    $quoteAddress->setDataUsingMethod($key, $address->getData($key));
                }
                $quoteAddress->setCollectShippingRates(true)->collectTotals();
                $options = $this->_prepareShippingOptions($quoteAddress, false, true);
                
                Mage::log("PayPal Express: Callback prepared " . count($options) . " shipping options", null, 'paypal_shipping.log');
                foreach ($options as $option) {
                    Mage::log("PayPal Express: Callback option - Code: " . $option->getCode() . 
                             ", Name: " . $option->getName() . ", Amount: " . $option->getAmount(), null, 'paypal_shipping.log');
                }
            } else {
                Mage::log("PayPal Express: Callback conditions not met - Address: " . ($address ? 'yes' : 'no') . 
                         ", Quote Address: " . ($quoteAddress ? 'yes' : 'no') . 
                         ", Virtual: " . ($this->_quote->getIsVirtual() ? 'yes' : 'no'), null, 'paypal_shipping.log');
            }
            
            $response = $this->_api->setShippingOptions($options)->formatShippingOptionsCallback();
            Mage::log("PayPal Express: Callback response: " . $response, null, 'paypal_shipping.log');

            // log request and response
            $debugData['response'] = $response;
            $logger->log($debugData);
            return $response;
        } catch (Exception $e) {
            Mage::log("PayPal Express: Callback error: " . $e->getMessage(), null, 'paypal_shipping.log');
            $logger->log($debugData);
            throw $e;
        }
    }

    /**
     * Set shipping method to quote, if needed
     * @param string $methodCode
     */
    public function updateShippingMethod($methodCode)
    {
        if (!$this->_quote->getIsVirtual() && $shippingAddress = $this->_quote->getShippingAddress()) {
            if ($methodCode != $shippingAddress->getShippingMethod()) {
                $this->_ignoreAddressValidation();
                $shippingAddress->setShippingMethod($methodCode)->setCollectShippingRates(true);
                $this->_quote->collectTotals()->save();
            }
        }
    }

    /**
     * Place the order and recurring payment profiles when customer returned from paypal
     * Until this moment all quote data must be valid
     *
     * @param string $token
     * @param string $shippingMethodCode
     */
    public function place($token, $shippingMethodCode = null)
    {
        if ($shippingMethodCode) {
            $this->updateShippingMethod($shippingMethodCode);
        }

        $isNewCustomer = false;
        switch ($this->getCheckoutMethod()) {
            case Mage_Checkout_Model_Type_Onepage::METHOD_GUEST:
                $this->_prepareGuestQuote();
                break;
            case Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER:
                $this->_prepareNewCustomerQuote();
                $isNewCustomer = true;
                break;
            default:
                $this->_prepareCustomerQuote();
                break;
        }

        $this->_ignoreAddressValidation();
        $this->_quote->collectTotals();
        $service = Mage::getModel('sales/service_quote', $this->_quote);
        $service->submitAll();
        $this->_quote->save();

        if ($isNewCustomer) {
            try {
                $this->_involveNewCustomer();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        $this->_recurringPaymentProfiles = $service->getRecurringPaymentProfiles();
        // TODO: send recurring profile emails

        /** @var $order Mage_Sales_Model_Order */
        $order = $service->getOrder();
        if (!$order) {
            return;
        }
        $this->_billingAgreement = $order->getPayment()->getBillingAgreement();

        // commence redirecting to finish payment, if paypal requires it
        if ($order->getPayment()->getAdditionalInformation(
                Mage_Paypal_Model_Express_Checkout::PAYMENT_INFO_TRANSPORT_REDIRECT
        )) {
            $this->_redirectUrl = $this->_config->getExpressCheckoutCompleteUrl($token);
        }

        switch ($order->getState()) {
            // even after placement paypal can disallow to authorize/capture, but will wait until bank transfers money
            case Mage_Sales_Model_Order::STATE_PENDING_PAYMENT:
                // TODO
                break;
            // regular placement, when everything is ok
            case Mage_Sales_Model_Order::STATE_PROCESSING:
            case Mage_Sales_Model_Order::STATE_COMPLETE:
            case Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW:
                $order->queueNewOrderEmail();
                break;
        }
        $this->_order = $order;
    }

    /**
     * Make sure addresses will be saved without validation errors
     */
    private function _ignoreAddressValidation()
    {
        $this->_quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$this->_quote->getIsVirtual()) {
            $this->_quote->getShippingAddress()->setShouldIgnoreValidation(true);
            if (!$this->_config->requireBillingAddress && !$this->_quote->getBillingAddress()->getEmail()) {
                $this->_quote->getBillingAddress()->setSameAsBilling(1);
            }
        }
    }

    /**
     * Determine whether redirect somewhere specifically is required
     *
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->_redirectUrl;
    }

    /**
     * Return recurring payment profiles
     *
     * @return array
     */
    public function getRecurringPaymentProfiles()
    {
        return $this->_recurringPaymentProfiles;
    }

    /**
     * Get created billing agreement
     *
     * @return Mage_Sales_Model_Billing_Agreement|null
     */
    public function getBillingAgreement()
    {
        return $this->_billingAgreement;
    }

    /**
     * Return order
     *
     * @return Mage_Sales_Model_Order
     */
    public function getOrder()
    {
        return $this->_order;
    }

    /**
     * Get checkout method
     *
     * @return string
     */
    public function getCheckoutMethod()
    {
        if ($this->getCustomerSession()->isLoggedIn()) {
            return Mage_Checkout_Model_Type_Onepage::METHOD_CUSTOMER;
        }
        if (!$this->_quote->getCheckoutMethod()) {
            if (Mage::helper('checkout')->isAllowedGuestCheckout($this->_quote)) {
                $this->_quote->setCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_GUEST);
            } else {
                $this->_quote->setCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER);
            }
        }
        return $this->_quote->getCheckoutMethod();
    }

    /**
     * Sets address data from exported address
     *
     * @param Mage_Sales_Model_Quote_Address $address
     * @param array $exportedAddress
     */
    protected function _setExportedAddressData($address, $exportedAddress)
    {
        // Exported data is more priority if we came from Express Checkout button
        $isButton  = (bool)$this->_quote->getPayment()->getAdditionalInformation(self::PAYMENT_INFO_BUTTON);
        if (!$isButton) {
            foreach ($exportedAddress->getExportedKeys() as $key) {
                $oldData = $address->getDataUsingMethod($key);
                $isEmpty = null;
                if (is_array($oldData)) {
                    foreach($oldData as $val) {
                        if(!empty($val)) {
                            $isEmpty = false;
                            break;
                        }
                        $isEmpty = true;
                    }
                }
                if (empty($oldData) || $isEmpty === true) {
                    $address->setDataUsingMethod($key, $exportedAddress->getData($key));
                }
            }
        } else {
            foreach ($exportedAddress->getExportedKeys() as $key) {
                $data = $exportedAddress->getData($key);
                if (!empty($data)) {
                    $address->setDataUsingMethod($key, $data);
                }
            }
        }
    }

    /**
     * Set create billing agreement flag to api call
     *
     * @return $this
     */
    protected function _setBillingAgreementRequest()
    {
        if (!$this->_customerId || $this->_quote->hasNominalItems()) {
            return $this;
        }

        $isRequested = $this->_isBARequested || $this->_quote->getPayment()
            ->getAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_BILLING_AGREEMENT);

        if (!($this->_config->allow_ba_signup == Mage_Paypal_Model_Config::EC_BA_SIGNUP_AUTO
            || $isRequested && $this->_config->shouldAskToCreateBillingAgreement())) {
            return $this;
        }

        if (!Mage::getModel('sales/billing_agreement')->needToCreateForCustomer($this->_customerId)) {
            return $this;
        }
        $this->_api->setBillingType($this->_api->getBillingAgreementType());
        return $this;
    }

    /**
     * @return Mage_Paypal_Model_Api_Nvp
     */
    protected function _getApi()
    {
        if (null === $this->_api) {
            $this->_api = Mage::getModel($this->_apiType)->setConfigObject($this->_config);
        }
        return $this->_api;
    }

    /**
     * Attempt to collect address shipping rates and return them for further usage in instant update API
     * Returns empty array if it was impossible to obtain any shipping rate
     * If there are shipping rates obtained, the method must return one of them as default.
     *
     * @param Mage_Sales_Model_Quote_Address $address
     * @param bool $mayReturnEmpty
     * @return array|false
     */
    protected function _prepareShippingOptions(
        Mage_Sales_Model_Quote_Address $address,
        $mayReturnEmpty = false, $calculateTax = false
    ) {
        Mage::log("PayPal Express: _prepareShippingOptions called - Country: " . $address->getCountryId() . 
                 ", Region: " . $address->getRegionId() . 
                 ", City: " . $address->getCity() . 
                 ", Postcode: " . $address->getPostcode(), 
                 null, 'paypal_shipping.log');
        
        $options = array(); $i = 0; $iMin = false; $min = false;
        $userSelectedOption = null;

        $allShippingRates = $address->getGroupedAllShippingRates();
        Mage::log("PayPal Express: Found " . count($allShippingRates) . " shipping rate groups", 
                 null, 'paypal_shipping.log');
        
        foreach ($allShippingRates as $carrierCode => $group) {
            Mage::log("PayPal Express: Carrier $carrierCode has " . count($group) . " rates", 
                     null, 'paypal_shipping.log');
            foreach ($group as $rate) {
                $amount = (float)$rate->getPrice();
                Mage::log("PayPal Express: Rate " . $rate->getCode() . " - $" . $amount . 
                         ($rate->getErrorMessage() ? " (ERROR: " . $rate->getErrorMessage() . ")" : ""), 
                         null, 'paypal_shipping.log');
                if ($rate->getErrorMessage()) {
                    continue;
                }
                $isDefault = $address->getShippingMethod() === $rate->getCode();
                $amountExclTax = Mage::helper('tax')->getShippingPrice($amount, false, $address);
                $amountInclTax = Mage::helper('tax')->getShippingPrice($amount, true, $address);

                // Clean shipping method title - remove carrier codes completely
                $cleanTitle = $rate->getMethodTitle();
                // Remove common carrier prefixes and method codes
                $cleanTitle = preg_replace('/^(tablerate_\w+\s*|flatrate_\w+\s*|freeshipping_\w+\s*)/i', '', $cleanTitle);
                $cleanTitle = trim($cleanTitle);
                if (empty($cleanTitle)) {
                    $cleanTitle = $rate->getMethodTitle();
                }

                $options[$i] = new Varien_Object(array(
                    'is_default' => $isDefault,
                    'name'       => '', // Empty to avoid duplication 
                    'code'       => $cleanTitle, // Use clean title as code so PayPal displays it nicely
                    'amount'     => $amountExclTax,
                ));
                
                // Store original code for later matching
                $options[$i]->setData('original_code', $rate->getCode());
                
                // Store mapping for later lookup when PayPal returns the selection
                // Also store with trimmed version to handle potential spacing issues
                $this->_storeShippingMethodMapping($cleanTitle, $rate->getCode());
                $this->_storeShippingMethodMapping(trim($cleanTitle), $rate->getCode());
                if ($calculateTax) {
                    $options[$i]->setTaxAmount(
                        $amountInclTax - $amountExclTax
                            + $address->getTaxAmount() - $address->getShippingTaxAmount()
                    );
                }
                if ($isDefault) {
                    $userSelectedOption = $options[$i];
                }
                if (false === $min || $amountInclTax < $min) {
                    $min = $amountInclTax;
                    $iMin = $i;
                }
                $i++;
            }
        }

        if ($mayReturnEmpty && is_null($userSelectedOption)) {
            if (count($options) > 0) {
                // Don't set any option as default to avoid PayPal total mismatch
                // Customer will be required to select shipping in PayPal
                Mage::log("PayPal Express: " . count($options) . " shipping options available, none pre-selected to avoid total mismatch", 
                         null, 'paypal_shipping.log');
            } else {
                // Use TBD shipping option instead of "no_rate" to enable callbacks
                $options[] = new Varien_Object(array(
                    'is_default' => true,
                    'name'       => 'Shipping (To Be Determined)',
                    'code'       => 'tbd_shipping',
                    'amount'     => 0.00,
                ));
                if ($calculateTax) {
                    $options[$i]->setTaxAmount($address->getTaxAmount());
                }
                Mage::log("PayPal Express: No shipping options available, using TBD shipping to enable callbacks", 
                         null, 'paypal_shipping.log');
            }
        } elseif (is_null($userSelectedOption) && isset($options[$iMin])) {
            $options[$iMin]->setIsDefault(true);
        }

        // Magento will transfer only first 10 cheapest shipping options if there are more than 10 available.
        if (count($options) > 10) {
            usort($options, array(get_class($this),'cmpShippingOptions'));
            array_splice($options, 10);
            // User selected option will be always included in options list
            if (!is_null($userSelectedOption) && !in_array($userSelectedOption, $options)) {
                $options[9] = $userSelectedOption;
            }
        }

        return $options;
    }


    /**
     * Create shipping method mapping for all available shipping methods in the system
     */
    protected function _createShippingMethodMapping()
    {
        // Get all active shipping methods from configuration
        $shippingMethods = Mage::getSingleton('shipping/config')->getActiveCarriers();
        $mapping = array();
        
        foreach ($shippingMethods as $carrierCode => $carrierModel) {
            if ($carrierModel->isActive()) {
                // Get all methods for this carrier
                $methods = $carrierModel->getAllowedMethods();
                if ($methods) {
                    foreach ($methods as $methodCode => $methodTitle) {
                        $fullCode = $carrierCode . '_' . $methodCode;
                        
                        // Clean the title
                        $cleanTitle = $methodTitle;
                        $cleanTitle = preg_replace('/^(tablerate_\w+\s*|flatrate_\w+\s*|freeshipping_\w+\s*)/i', '', $cleanTitle);
                        $cleanTitle = trim($cleanTitle);
                        if (empty($cleanTitle)) {
                            $cleanTitle = $methodTitle;
                        }
                        
                        // Store the mapping
                        $mapping[$cleanTitle] = $fullCode;
                        $mapping[trim($cleanTitle)] = $fullCode; // Also store trimmed version
                        
                        Mage::log("PayPal Express: Pre-mapped '{$cleanTitle}' => '{$fullCode}'", 
                                 null, 'paypal_shipping.log');
                    }
                }
            }
        }
        
        // Store in quote payment additional information
        $payment = $this->_quote->getPayment();
        $payment->setAdditionalInformation('paypal_shipping_mapping', $mapping);
        
        // Also store in PayPal session as backup
        $session = Mage::getSingleton('paypal/session');
        $session->setData('shipping_method_mapping', $mapping);
        
        Mage::log("PayPal Express: Created mapping for " . count($mapping) . " shipping methods", 
                 null, 'paypal_shipping.log');
    }

    /**
     * Store shipping method mapping for clean titles to original codes
     *
     * @param string $cleanTitle
     * @param string $originalCode
     */
    protected function _storeShippingMethodMapping($cleanTitle, $originalCode)
    {
        // Store in quote payment additional information for persistence across PayPal redirect
        $payment = $this->_quote->getPayment();
        $mapping = $payment->getAdditionalInformation('paypal_shipping_mapping') ?: array();
        $mapping[$cleanTitle] = $originalCode;
        $payment->setAdditionalInformation('paypal_shipping_mapping', $mapping);
        
        // Also store in PayPal session as backup
        $session = Mage::getSingleton('paypal/session');
        $sessionMapping = $session->getData('shipping_method_mapping') ?: array();
        $sessionMapping[$cleanTitle] = $originalCode;
        $session->setData('shipping_method_mapping', $sessionMapping);
        
        Mage::log("PayPal Express: Stored mapping '{$cleanTitle}' => '{$originalCode}' in quote and session", 
                 null, 'paypal_shipping.log');
    }

    /**
     * Get original shipping method code from clean title
     *
     * @param string $cleanTitle
     * @return string|null
     */
    protected function _getOriginalShippingMethodCode($cleanTitle)
    {
        // Trim the input to handle spacing issues
        $cleanTitle = trim($cleanTitle);
        
        // Try to get mapping from quote payment additional information first
        $payment = $this->_quote->getPayment();
        $mapping = $payment->getAdditionalInformation('paypal_shipping_mapping') ?: array();
        
        // If not found in quote, try PayPal session as backup
        if (empty($mapping)) {
            $session = Mage::getSingleton('paypal/session');
            $mapping = $session->getData('shipping_method_mapping') ?: array();
        }
        
        Mage::log("PayPal Express: Looking for mapping of '{$cleanTitle}' in: " . print_r($mapping, true), 
                 null, 'paypal_shipping.log');
        
        if (isset($mapping[$cleanTitle])) {
            Mage::log("PayPal Express: Found mapping '{$cleanTitle}' => '{$mapping[$cleanTitle]}'", 
                     null, 'paypal_shipping.log');
            return $mapping[$cleanTitle];
        }
        
        // Try to find a partial match (case-insensitive, trimmed)
        foreach ($mapping as $storedClean => $storedOriginal) {
            if (trim(strtolower($storedClean)) === strtolower($cleanTitle)) {
                Mage::log("PayPal Express: Found case-insensitive mapping '{$cleanTitle}' => '{$storedOriginal}'", 
                         null, 'paypal_shipping.log');
                return $storedOriginal;
            }
        }
        
        // Fallback: try to find a match by checking if any stored original codes match this clean title
        foreach ($mapping as $storedClean => $storedOriginal) {
            if ($storedClean === $cleanTitle || $storedOriginal === $cleanTitle) {
                Mage::log("PayPal Express: Found fallback mapping '{$cleanTitle}' => '{$storedOriginal}'", 
                         null, 'paypal_shipping.log');
                return $storedOriginal;
            }
        }
        
        Mage::log("PayPal Express: No mapping found for '{$cleanTitle}' - available mappings: " . print_r(array_keys($mapping), true), 
                 null, 'paypal_shipping.log');
        
        // Last resort: if the cleanTitle looks like an original shipping method code, use it as-is
        if (strpos($cleanTitle, '_') !== false) {
            Mage::log("PayPal Express: Using '{$cleanTitle}' as-is (appears to be original code)", 
                     null, 'paypal_shipping.log');
            return $cleanTitle;
        }
        
        return null;
    }

    /**
     * Compare two shipping options based on their amounts
     *
     * This function is used as a callback comparison function in shipping options sorting process
     * @see self::_prepareShippingOptions()
     *
     * @param Varien_Object $option1
     * @param Varien_Object $option2
     * @return integer
     */
    protected static function cmpShippingOptions(Varien_Object $option1, Varien_Object $option2)
    {
        if ($option1->getAmount() == $option2->getAmount()) {
            return 0;
        }
        return ($option1->getAmount() < $option2->getAmount()) ? -1 : 1;
    }

    /**
     * Try to find whether the code provided by PayPal corresponds to any of possible shipping rates
     * This method was created only because PayPal has issues with returning the selected code.
     * If in future the issue is fixed, we don't need to attempt to match it. It would be enough to set the method code
     * before collecting shipping rates
     *
     * @param Mage_Sales_Model_Quote_Address $address
     * @param string $selectedCode
     * @return string
     */
    protected function _matchShippingMethodCode(Mage_Sales_Model_Quote_Address $address, $selectedCode)
    {
        $options = $this->_prepareShippingOptions($address, false);
        foreach ($options as $option) {
            if ($selectedCode === $option['code'] // the proper case as outlined in documentation
                || $selectedCode === $option['name'] // workaround: PayPal may return name instead of the code
                // workaround: PayPal may concatenate code and name, and return it instead of the code:
                || $selectedCode === "{$option['code']} {$option['name']}"
            ) {
                return $option['code'];
            }
        }
        return '';
    }

    /**
     * Prepare quote for guest checkout order submit
     *
     * @return $this
     */
    protected function _prepareGuestQuote()
    {
        $quote = $this->_quote;
        $quote->setCustomerId(null)
            ->setCustomerEmail($quote->getBillingAddress()->getEmail())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);
        return $this;
    }

    /**
     * Checks if customer with email coming from Express checkout exists
     *
     * @return int
     */
    protected function _lookupCustomerId()
    {
        return Mage::getModel('customer/customer')
            ->setWebsiteId(Mage::app()->getWebsite()->getId())
            ->loadByEmail($this->_quote->getCustomerEmail())
            ->getId();
    }

    /**
     * Prepare quote for customer registration and customer order submit
     * and restore magento customer data from quote
     *
     * @return $this
     */
    protected function _prepareNewCustomerQuote()
    {
        $quote      = $this->_quote;
        $billing    = $quote->getBillingAddress();
        $shipping   = $quote->isVirtual() ? null : $quote->getShippingAddress();

        $customerId = $this->_lookupCustomerId();
        if ($customerId && !$this->_customerEmailExists($quote->getCustomerEmail())) {
            $this->getCustomerSession()->loginById($customerId);
            return $this->_prepareCustomerQuote();
        }

        $customer = $quote->getCustomer();
        /** @var $customer Mage_Customer_Model_Customer */
        $customerBilling = $billing->exportCustomerAddress();
        $customer->addAddress($customerBilling);
        $billing->setCustomerAddress($customerBilling);
        $customerBilling->setIsDefaultBilling(true);
        if ($shipping && !$shipping->getSameAsBilling()) {
            $customerShipping = $shipping->exportCustomerAddress();
            $customer->addAddress($customerShipping);
            $shipping->setCustomerAddress($customerShipping);
            $customerShipping->setIsDefaultShipping(true);
        } elseif ($shipping) {
            $customerBilling->setIsDefaultShipping(true);
        }
        /**
         * @todo integration with dynamica attributes customer_dob, customer_taxvat, customer_gender
         */
        if ($quote->getCustomerDob() && !$billing->getCustomerDob()) {
            $billing->setCustomerDob($quote->getCustomerDob());
        }

        if ($quote->getCustomerTaxvat() && !$billing->getCustomerTaxvat()) {
            $billing->setCustomerTaxvat($quote->getCustomerTaxvat());
        }

        if ($quote->getCustomerGender() && !$billing->getCustomerGender()) {
            $billing->setCustomerGender($quote->getCustomerGender());
        }

        Mage::helper('core')->copyFieldset('checkout_onepage_billing', 'to_customer', $billing, $customer);
        $customer->setEmail($quote->getCustomerEmail());
        $customer->setPrefix($quote->getCustomerPrefix());
        $customer->setFirstname($quote->getCustomerFirstname());
        $customer->setMiddlename($quote->getCustomerMiddlename());
        $customer->setLastname($quote->getCustomerLastname());
        $customer->setSuffix($quote->getCustomerSuffix());
        $customer->setPassword($customer->decryptPassword($quote->getPasswordHash()));
        $customer->setPasswordHash($customer->hashPassword($customer->getPassword()));
        
        try {
            $customer->save();
            $quote->setCustomer($customer);
        } catch (\Throwable $th) {
            // Customer save fails if email exists, but it is checked above, so something odd happening
        }
        
        $quote->setPasswordHash('');

        return $this;
    }

    /**
     * Prepare quote for customer order submit
     *
     * @return $this
     */
    protected function _prepareCustomerQuote()
    {
        $quote      = $this->_quote;
        $billing    = $quote->getBillingAddress();
        $shipping   = $quote->isVirtual() ? null : $quote->getShippingAddress();

        $customer = $this->getCustomerSession()->getCustomer();
        if (!$billing->getCustomerId() || $billing->getSaveInAddressBook()) {
            $customerBilling = $billing->exportCustomerAddress();
            $customer->addAddress($customerBilling);
            $billing->setCustomerAddress($customerBilling);
        }
        if ($shipping && ((!$shipping->getCustomerId() && !$shipping->getSameAsBilling())
            || (!$shipping->getSameAsBilling() && $shipping->getSaveInAddressBook()))) {
            $customerShipping = $shipping->exportCustomerAddress();
            $customer->addAddress($customerShipping);
            $shipping->setCustomerAddress($customerShipping);
        }

        if (isset($customerBilling) && !$customer->getDefaultBilling()) {
            $customerBilling->setIsDefaultBilling(true);
        }
        if ($shipping && isset($customerBilling) && !$customer->getDefaultShipping() && $shipping->getSameAsBilling()) {
            $customerBilling->setIsDefaultShipping(true);
        } elseif ($shipping && isset($customerShipping) && !$customer->getDefaultShipping()) {
            $customerShipping->setIsDefaultShipping(true);
        }
        $quote->setCustomer($customer);

        return $this;
    }

    /**
     * Involve new customer to system
     *
     * @return $this
     */
    protected function _involveNewCustomer()
    {
        $customer = $this->_quote->getCustomer();
        if ($customer->isConfirmationRequired()) {
            $customer->sendNewAccountEmail('confirmation', '', $this->_quote->getStoreId());
            $url = Mage::helper('customer')->getEmailConfirmationUrl($customer->getEmail());
            $this->getCustomerSession()->addSuccess(
                Mage::helper('customer')->__('Account confirmation is required. Please, check your e-mail for confirmation link. To resend confirmation email please <a href="%s">click here</a>.', $url)
            );
        } else {
            $customer->sendNewAccountEmail('registered', '', $this->_quote->getStoreId());
            $this->getCustomerSession()->loginById($customer->getId());
        }
        return $this;
    }

    /**
     * Get customer session object
     *
     * @return Mage_Customer_Model_Session
     */
    public function getCustomerSession()
    {
        return $this->_customerSession;
    }

    /**
     * Check if customer email exists
     *
     * @param string $email
     * @return bool
     */
    protected function _customerEmailExists($email)
    {
        $result    = false;
        $customer  = Mage::getModel('customer/customer');
        $websiteId = Mage::app()->getStore()->getWebsiteId();
        if (!is_null($websiteId)) {
            $customer->setWebsiteId($websiteId);
        }
        $customer->loadByEmail($email);
        if (!is_null($customer->getId())) {
            $result = true;
        }

        return $result;
    }
}
