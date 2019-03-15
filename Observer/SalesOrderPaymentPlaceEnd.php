<?php

namespace NoFraud\Connect\Observer;

use Magento\Sales\Model\Order;

class SalesOrderPaymentPlaceEnd implements \Magento\Framework\Event\ObserverInterface
{

    public function __construct(
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Api\RequestHandler $requestHandler,
        \NoFraud\Connect\Api\ResponseHandler $responseHandler,
        \NoFraud\Connect\Logger\Logger $logger,
        \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $orderStatusCollection
    ) {
        $this->configHelper = $configHelper;
        $this->requestHandler = $requestHandler;
        $this->responseHandler = $responseHandler;
        $this->logger = $logger;
        $this->orderStatusCollection = $orderStatusCollection;
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



        // get \Magento\Sales\Model\Order
        //
        $order = $payment->getOrder();

        // If Orders with the current Order's Status are ignored, then do nothing.
        //
        if ( $this->configHelper->orderStatusIsIgnored($order) ){
            return;
        }

        // If the payment has NOT been processed by a payment processor, AND
        // is NOT an offline payment method, then do nothing.
        //
        // Some payment processors like Authorize.net may cause this Event to fire
        // multiple times, but the logic below this point should not be executed
        // unless the Payment has a `last_trans_id` attribute.
        //
        if ( !$payment->getLastTransId() && !$payment->getMethodInstance()->isOffline() ){
            return;
        }



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
        $resultMap = $this->requestHandler->send($request, $apiUrl);



        // Log request results with associated invoice number:
        //
        $this->logger->logTransactionResults($order, $payment, $resultMap); //LOGGING



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
            }

            // Update state and status. Run function for holded status.
            if ( !empty($newStatus) ){
                $newState = $this->stateFromStatus($newStatus);

                if ($newStatus == Order::STATE_HOLDED) {
                    $order->hold();
                } else if ($newState) {
                    $order->setStatus($newStatus)->setState($newState);
                }
            }

            // Finally, save the Order:
            //
            $order->save();

        } catch ( \Exception $exception ) {
            $this->logger->logFailure($order, $exception); //LOGGING
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
            $statusCode = $this->configHelper->getCustomStatusConfig($key);
            return $statusCode;
        }
    }

    protected function stateFromStatus($state)
    {
        $statuses = $this->orderStatusCollection->create()->joinStates();
        $stateIndex = [];

        foreach ($statuses as $status) {
            $stateIndex[$status->getStatus()] = $status->getState();
        }

        return $stateIndex[$state] ?? null;
    }
}
