<?php
 
namespace NoFraud\Connect\Api;
 
class RequestHandler
{
    const PRODUCTION_URL = 'https://api.nofraud.com/';
    const SANDBOX_URL    = 'https://apitest.nofraud.com/';

    const DEFAULT_AVS_CODE = 'U';
    const DEFAULT_CVV_CODE = 'U';

    protected $CcTypeMap = [
        'ae' => "Amex",
        'di' => "Discover",
        'mc' => "Mastercard",
        'vs' => "Visa",
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

        return $this->scrubEmptyValues($params);
    }

    /**
     * @param array  $params | NoFraud request object parameters
     * @param string $apiUrl | The URL to send to
     */
    public function send( $params, $apiUrl )
    {
        $ch = curl_init();

        // Do not set POST params for Order status requests:
        //
        if ( isset( $params['status_request'] ) == false ){
            $body = json_encode($params);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($body)));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_URL, $apiUrl );
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $result          = curl_exec($ch);
        $responseBody    = json_decode($result, true);
        $responseCode    = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $httpClientError = curl_error($ch);
        curl_close($ch);

        $response = [
            'http_code' => $responseCode,
            'body'      => $responseBody,
        ];

        if ( !empty($httpClientError) ){
            $response['http_client_error'] = $httpClientError;
        }

        return $response;
    }

    public function noFraudRecordAlreadyExists( $order, $apiToken, $apiUrl )
    {
        $response = $this->getOrderStatus( $order, $apiToken, $apiUrl );

        return isset( $response['body']['decision'] );
    }

    public function getOrderStatus( $order, $apiToken, $apiUrl )
    {
        $transactionId = $order->getIncrementId();

        $apiUrl .= 'status/' . $apiToken . '/' . $transactionId ;

        $response = $this->send( [ 'status_request' => true ], $apiUrl );

        // DEBUG
        $this->logger->info( print_r( ["Status request for {$transactionId}" => $response], true ) );

        return $response;
    }


    protected function buildBaseParams( $payment, $order, $apiToken )
    {
        $baseParams = [];

        $baseParams['nf-token']       = $apiToken;
        $baseParams['amount']         = $this->formatTotal( $order->getGrandTotal() );
        $baseParams['currency_code']  = $order->getOrderCurrencyCode();
        $baseParams['customerIP']     = $order->getRemoteIp();
        $baseParams['shippingAmount'] = $this->formatTotal( $order->getShippingAmount() );
        $baseParams['avsResultCode']  = self::DEFAULT_AVS_CODE;
        $baseParams['cvvResultCode']  = self::DEFAULT_CVV_CODE;

        if (!empty( $order->getXForwardedFor() )){
            $baseParams['customerIP'] = $order->getXForwardedFor();
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
        $cc['cardNumber']     = $payment->getData('cc_number');
        $cc['expirationDate'] = $this->buildCcExpDate($payment);
        $cc['cardCode']       = $payment->getData('cc_cid'); 
        if ( strlen($payment->getCcLast4()) == 4 ){
            $cc['last4']      = $payment->getCcLast4();
        }

        $paymentParams = [];

        $paymentParams['creditCard'] = $cc;

        return $paymentParams;
    }

    protected function formatCcType( $code )
    {
        if ( empty($code) ){
            return;
        }

        if ( !isset($this->CcTypeMap[strtolower($code)]) ){
            return $code;
        }

        return $this->CcTypeMap[$code];
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
        $addressParams['address']   = join( ' ', $address->getStreet() );
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

    protected function scrubEmptyValues($params)
    {
        // Removes any empty values (except for 'empty' numerical values such as 0 or 00.00)
        foreach ($params as $key => $value) {

            if (is_array($value)) {
                $value = $this->scrubEmptyValues($value);
                $params[$key] = $value;
            }

            if ( empty($value) && !is_numeric($value) ) {
                unset($params[$key]);
            }

        }

        return $params;
    }
}
