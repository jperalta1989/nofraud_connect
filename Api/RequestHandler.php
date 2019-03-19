<?php
 
namespace NoFraud\Connect\Api;
 
class RequestHandler
{
    const PRODUCTION_URL = 'https://api.nofraud.com/';
    const SANDBOX_URL    = 'https://apitest.nofraud.com/';

    const DEFAULT_AVS_CODE = 'U';
    const DEFAULT_CVV_CODE = 'U';

    protected $ccTypeMap = [
        'ae' => 'Amex',
        'di' => 'Discover',
        'mc' => 'Mastercard',
        'vs' => 'Visa',
        'vi' => 'Visa',
    ];

    public function __construct(
        \Magento\Directory\Model\Currency $currency,
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->currency = $currency;
        $this->logger = $logger;
    }

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param \Magento\Sales\Model\Order $order
     * @param string $apiToken | NoFraud API Token
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

    /**
     * @param array  $params | NoFraud request object parameters
     * @param string $apiUrl | The URL to send to
     */
    public function send( $params, $apiUrl, $requestType = 'POST')
    {
        $ch = curl_init();

        if (!strcasecmp($requestType,'post')) {
            $body = json_encode($params);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($body)));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_URL, $apiUrl );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($ch);
        if(curl_errno($ch)){
            $this->logger->logApiError($apiUrl, $curl_error($ch));
        }

        $response = [
            'http' => [
                'response' => [
                    'body' => json_decode($result, true),
                    'code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                    'time' => curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME),
                ],
                'client' => [
                    'error' => curl_error($ch),
                ],
            ],
        ];

        curl_close($ch);

        return $this->scrubEmptyValues($response);
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

        return $customerParams;
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

            case \Magento\Braintree\Model\Ui\ConfigProvider::CODE:

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

            default:
                $params = [];
                break;

        }

        return $this->scrubEmptyValues($params);
    }


    protected function scrubEmptyValues($array)
    {
        // Removes any empty values (except for 'empty' numerical values such as 0 or 00.00)
        foreach ($array as $key => $value) {

            if (is_array($value)) {
                $value = $this->scrubEmptyValues($value);
                $array[$key] = $value;
            }

            if ( empty($value) && !is_numeric($value) ) {
                unset($array[$key]);
            }

        }

        return $array;
    }
}
