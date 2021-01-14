<?php

namespace NoFraud\Connect\Api;

use Magento\Framework\Simplexml\Element;
use NoFraud\Connect\Logger\Logger;

class RequestHandler extends \NoFraud\Connect\Api\Request\Handler\AbstractHandler
{
    const DEFAULT_AVS_CODE = 'U';
    const DEFAULT_CVV_CODE = 'U';
    const BRAINTREE_CODE = 'braintree';
    const MAGEDELIGHT_AUTHNET_CIM_METHOD_CODE = 'md_authorizecim';
    const PL_MI_METHOD_CODE = 'nmi_directpost';

    protected $currency;
    protected $customerRepository;
    protected $customer;
    protected $orderCollectionFactory;

    protected $ccTypeMap = [
        'ae' => 'Amex',
        'americanexpress' => 'Amex',
        'di' => 'Discover',
        'mc' => 'Mastercard',
        'vs' => 'Visa',
        'vi' => 'Visa',
    ];

    public function __construct(
        \NoFraud\Connect\Logger\Logger $logger,
        \Magento\Directory\Model\Currency $currency,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Customer\Model\Customer $customer,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactoryInterface $orderCollectionFactory
    ) {

        parent::__construct($logger);

        $this->currency = $currency;
        $this->customerRepository = $customerRepository;
        $this->customer = $customer;
        $this->orderCollectionFactory = $orderCollectionFactory;
    }

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param \Magento\Sales\Model\Order $order
     * @param string $apiToken | NoFraud API Token
     *
     * @return array
     */
    public function build( $payment, $order, $apiToken )
    {
        $params             = $this->buildBaseParams( $payment, $order, $apiToken );
        $params['customer'] = $this->buildCustomerParams( $order );
        $params['order']    = $this->buildOrderParams( $order );
        $params['payment']  = $this->buildPaymentParams( $payment );
        $params['billTo']   = $this->buildAddressParams( $order->getBillingAddress(), true );
        $params['shipTo']   = $this->buildAddressParams( $order->getShippingAddress() );
        $params['lineItems'] = $this->buildLineItemsParams( $order->getItems() );

        $paramsAdditionalInfo = $this->buildParamsAdditionalInfo( $payment );
        $params = array_replace_recursive( $params, $paramsAdditionalInfo );

        return $this->scrubEmptyValues($params);
    }

    protected function buildBaseParams( $payment, $order, $apiToken )
    {
        $baseParams = [];

        $baseParams['nf-token']       = $apiToken;
        $baseParams['amount']         = $this->formatTotal( $order->getGrandTotal() );
        $baseParams['currency_code']  = $order->getOrderCurrencyCode();
        $baseParams['shippingAmount'] = $this->formatTotal( $order->getShippingAmount() );
        $baseParams['avsResultCode']  = self::DEFAULT_AVS_CODE;
        $baseParams['cvvResultCode']  = self::DEFAULT_CVV_CODE;

        if (empty( $order->getXForwardedFor() )){
            $baseParams['customerIP'] = $order->getRemoteIp();
        } else {
            //get original customer Ip address (in case customer is being routed through proxies)
            //Syntax: X-Forwarded-For: <client>, <proxy1>, <proxy2>
            $ips = array_filter(explode( ', ', $order->getXForwardedFor()));
            $baseParams['customerIP'] = $ips[0];
        }

        if (!empty( $payment->getCcAvsStatus() )) {
            $baseParams['avsResultCode'] = $payment->getCcAvsStatus();
        }

        if (!empty( $payment->getCcCidStatus() )) {
            $baseParams['cvvResultCode'] = $payment->getCcCidStatus();
        }

        return $baseParams;
    }

    protected function buildCustomerParams( $order )
    {
        $customerParams = [];

        $customerParams['email'] = $order->getCustomerEmail();

        if(!$this->_doesCustomerExist($order->getCustomerEmail(), $order->getStoreId())){
            return $customerParams;
        }

        $customer = $this->customerRepository->get($order->getCustomerEmail(), $order->getStoreId());
        if(!empty($customer->getId())){
            $customerParams['joined_on'] = date('m/d/Y', strtotime($customer->getCreatedAt()));
        }

        $orders = $this->getCustomerOrders($customer->getId());
        if(!empty($orders)){
            $totalPurchaseValue = 0;
            foreach ($orders as $order){
                $totalPurchaseValue += $order->getGrandTotal();
            }
            $lastPurchaseOrder = reset($orders);

            $customerParams['last_purchase_date'] = date('m/d/Y', strtotime($lastPurchaseOrder->getCreatedAt()));
            $customerParams['total_previous_purchases'] = sizeof($orders);
            $customerParams['total_purchase_value'] = $totalPurchaseValue;
        }

        return $customerParams;
    }

    private function _doesCustomerExist($email, $websiteId = null){
        $customer = $this->customer;
        if ($websiteId) {
            $customer->setWebsiteId($websiteId);
        }

        $customer->loadByEmail($email);

        if ($customer->getId()) {
            return true;
        }

        return false;
    }

    protected function getCustomerOrders($customerId)
    {
        return $this->orderCollectionFactory->create(
            $customerId
        )->addFieldToSelect(
            '*'
        )->setOrder(
            'created_at',
            'desc'
        )->getItems();
    }

    protected function buildOrderParams( $order )
    {
        $orderParams = [];

        $orderParams['invoiceNumber'] = $order->getIncrementId();

        return $orderParams;
    }

    protected function buildPaymentParams( $payment )
    {
        $cc = [];

        $cc['cardType']       = $this->formatCcType( $payment->getCcType() );
        $cc['cardNumber']     = $payment->getCcNumber();
        $cc['expirationDate'] = $this->buildCcExpDate($payment);
        $cc['cardCode']       = $payment->getCcCid();

        $cc['last4']          = $this->decryptLast4($payment);

        $paymentParams = [];

        $paymentParams['creditCard'] = $cc;
        $paymentParams['method'] = str_replace('_', ' ', $payment->getMethod());

        return $paymentParams;
    }

    protected function decryptLast4( $payment )
    {
        $last4 = $payment->getCcLast4();

        if ( !empty($last4) && strlen($last4) != 4 ){
            $last4 = $payment->decrypt($last4);
        }

        if ( strlen($last4) == 4 ){
            return $last4;
        }
    }

    protected function formatCcType( $code )
    {
        if ( empty($code) ){
            return;
        }

        $codeKey = strtolower($code);

        if ( !isset($this->ccTypeMap[$codeKey]) ){
            return $code;
        }

        return $this->ccTypeMap[$codeKey];
    }

    protected function buildCcExpDate( $payment )
    {
        $expMonth = $payment->getCcExpMonth();
        $expYear = $payment->getCcExpYear();

        // Pad a one-digit month with a 0;
        if ( strlen($expMonth) == 1 ){
            $expMonth = "0" . $expMonth;
        }

        // NoFraud requires an expiration month;
        // If month is not valid, return nothing;
        if ( !in_array($expMonth, ['01','02','03','04','05','06','07','08','09','10','11','12']) ){
            return;
        }

        // NoFraud requires an expiration year;
        // If year is invalid, return nothing;
        // Else if year is four digits (1999), truncate it to two (99);
        if (strlen($expYear) > 4){
            return;
        } elseif ( strlen($expYear) == 4 ){
            $expYear = substr($expYear, -2);
        }

        // Return the expiration date in the format MMYY;
        return $expMonth . $expYear;
    }

    protected function buildAddressParams( $address, $includePhoneNumber = false )
    {
        if ( empty($address) ){
            return;
        }

        $addressParams = [];

        $addressParams['firstName'] = $address->getFirstname();
        $addressParams['lastName']  = $address->getLastname();
        $addressParams['company']   = $address->getCompany();
        $addressParams['address']   = implode( ' ', $address->getStreet() );
        $addressParams['city']      = $address->getCity();
        $addressParams['state']     = $address->getRegionCode();
        $addressParams['zip']       = $address->getPostcode();
        $addressParams['country']   = $address->getCountryId();

        if ( $includePhoneNumber ){
            $addressParams['phoneNumber'] = $address->getTelephone();
        }

        return $addressParams;
    }

    protected function buildLineItemsParams( $orderItems )
    {
        if ( empty($orderItems) ){
            return;
        }

        $lineItemsParams = [];

        foreach ( $orderItems as $item ){
            if ($item->getParentItem()) {
                continue;
            }

            $lineItem = [];

            $lineItem['sku']      = $item->getSku();
            $lineItem['name']     = $item->getName();
            $lineItem['price']    = $this->formatTotal( $item->getPrice() );
            $lineItem['quantity'] = $item->getQtyOrdered();

            $lineItemsParams[] = $lineItem;
        }

        return $lineItemsParams;
    }

    protected function formatTotal( $amount )
    {
        if ( empty($amount) ){
            return;
        }

        return $this->currency->formatTxt( $amount, ['display' => \Magento\Framework\Currency::NO_SYMBOL] );
    }

    protected function buildParamsAdditionalInfo( $payment )
    {
        $info = $payment->getAdditionalInformation();

        if ( empty($info) ){
            return [];
        }

        $method = $payment->getMethod();

        switch ( $method ) {

            case \Magento\Paypal\Model\Config::METHOD_PAYFLOWPRO:

                $last4 = $info['cc_details']['cc_last_4'] ?? NULL;
                $sAvs  = $info['avsaddr']   ?? NULL; // AVS Street Address Match
                $zAvs  = $info['avszip']    ?? NULL; // AVS Zip Code Match
                $iAvs  = $info['iavs']      ?? NULL; // International AVS Response
                $cvv   = $info['cvv2match'] ?? NULL;

                $params = [
                    "payment" => [
                        "creditCard" => [
                            "last4" => $last4,
                        ],
                    ],
                    "avsResultCode" => $sAvs . $zAvs . $iAvs,
                    "cvvResultCode" => $cvv,
                ];

                break;

            case self::BRAINTREE_CODE:

                $last4    = substr( $info['cc_number'] ?? [], -4 );
                $cardType = $info['cc_type'] ?? NULL;
                $sAvs     = $info['avsStreetAddressResponseCode'] ?? NULL; // AVS Street Address Match
                $zAvs     = $info['avsPostalCodeResponseCode']    ?? NULL; // AVS Zip Code Match
                $cvv      = $info['cvvResponseCode'] ?? NULL;

                $params = [
                    "payment" => [
                        "creditCard" => [
                            "last4"    => $last4,
                            "cardType" => $cardType,
                        ],
                    ],
                    "avsResultCode" => $sAvs . $zAvs,
                    "cvvResultCode" => $cvv,
                ];

                break;

            case self::MAGEDELIGHT_AUTHNET_CIM_METHOD_CODE:
                $avs = $payment->getCcAvsStatus();
                $cid = $payment->getCcCidStatus();

                if(!is_string($cid) && $cid instanceof Element)
                {
                    $cid = $cid->asArray();
                }

                $params = [
                    "avsResultCode" => $avs,
                    "cvvResultCode" => $cid,
                ];

                break;

            case self::PL_MI_METHOD_CODE:
                $addInfo = json_decode($payment->getAdditionalInformation('payment_additional_info'), true);
                $avs = $addInfo['avsresponse'];
                $cid = $addInfo['cvvresponse'];

                $params = [
                    "avsResultCode" => $avs,
                    "cvvResultCode" => $cid,
                ];

                break;

            default:
                $params = [];
                break;

        }

        return $this->scrubEmptyValues($params);
    }
}
