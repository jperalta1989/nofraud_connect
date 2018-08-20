<?php

namespace NoFraud\Connect\Observer;

class SalesOrderPaymentPlaceEnd implements \Magento\Framework\Event\ObserverInterface
{

    public function __construct(
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Api\RequestHandler $requestHandler,
        \NoFraud\Connect\Api\ResponseHandler $responseHandler,
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->configHelper = $configHelper;
        $this->requestHandler = $requestHandler;
        $this->responseHandler = $responseHandler;
        $this->logger = $logger;
    }

    public function execute( \Magento\Framework\Event\Observer $observer )
    {
        // If module is disabled from Admin Config, do nothing.
        //
        if ( $this->configHelper->noFraudIsDisabled() ) {
            return;
        }



        // get \Magento\Sales\Model\Order\Payment
        //
        $payment = $observer->getEvent()->getPayment();

        // PHASE2: This line should be implemented in a later version:
        // If the Payment method is blacklisted in the Admin Config, then do nothing.
        //
        /*
        if ( $this->configHelper->paymentMethodIsIgnored($payment->getMethod()) ) {
            return;
        }
        */

        // If the payment has not yet been processed
        // by the payment processor, then do nothing.
        //
        // Some payment processors like Authorize.net may cause this Event to fire
        // multiple times, but the logic below this point should not be executed
        // unless the Payment has a `last_trans_id` attribute.
        //
        if ( !$payment->getLastTransId() ){
            return;
        }


        // get \Magento\Sales\Model\Order
        //
        $order = $payment->getOrder();



        // get NoFraud Api Token
        //
        $apiToken = $this->configHelper->getApiToken();

        // Use the NoFraud Sandbox URL if Sandbox Mode is enabled in Admin Config:
        // 
        $apiUrl = $this->configHelper->getSandboxMode() ?
            $this->requestHandler::SANDBOX_URL          :
            $this->requestHandler::PRODUCTION_URL       ;

        // Build the NoFraud API request JSON from the Payment and Order objects:
        //
        $request = $this->requestHandler->build(
            $payment,
            $order, 
            $apiToken
        );
 
        // Send the request to the NoFraud API and get the response:
        //
        $resultMap = $this->requestHandler->send($request, $apiUrl);



        // Log request results with associated invoice number:
        //
        $this->logger->logTransactionResults($order, $payment, $resultMap);



        try {

            // For all API responses (official results from NoFraud, client errors, etc.),
            // add an informative comment to the Order in Magento Admin:
            // 
            $comment = $this->responseHandler->buildComment($resultMap);
            if ( !empty($comment) ){
                $order->addStatusHistoryComment($comment);
            }

            // For official results from from NoFraud, update the order status 
            // according to Admin Config preferences:
            //
            if ( isset( $resultMap['http']['response']['body'] ) ){
                $newStatus = $this->orderStatusFromConfig( $resultMap['http']['response']['body'] );
                if ( !empty($newStatus) ){
                    $this->logger->info("Attempting to set Order status {$newStatus}...");
                    $order->setStatus( $newStatus );
                }
            }

            // Finally, save the Order:
            //
            $order->save();

        } catch ( \Exception $exception ) {
            $this->logger->logFailure($order, $exception);
        }

    }

    protected function orderStatusFromConfig( $responseBody )
    {
        if ( isset($responseBody['decision']) ){
            $key = $responseBody['decision'];
        }

        if ( isset($responseBody['Errors']) ){
            $key = 'error';
        }
        

        if ( isset($key) ){
            $this->logger->info("key set to {$key}"); //DEBUG

            $statusCode = $this->configHelper->getCustomStatusConfig($key);

            $this->logger->info("got code '{$statusCode}' from config based on key '{$key}'"); //DEBUG

            return $statusCode;
        }
    }

}
