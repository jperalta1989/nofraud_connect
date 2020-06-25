<?php
namespace NoFraud\Connect\Logger;

class Logger extends \Monolog\Logger
{
    public function logTransactionResults($order, $payment, $resultMap)
    {
        $orderLog['id'] = $order->getIncrementId();

        $paymentLog['method'] = $payment->getMethod();

        $info = [
            'order' => $orderLog,
            'payment' => $paymentLog,
            'api_result' => $resultMap,
        ];

        $this->info(json_encode($info));
    }

    public function logCancelTransactionResults($order, $resultMap)
    {
        $orderLog['id'] = $order->getIncrementId();

        $info = [
            'order' => $orderLog,
            'api_result' => $resultMap,
        ];

        $this->info(json_encode($info));
    }

    public function logFailure($order, $exception)
    {
        $orderId = $order->getIncrementId();
        $this->critical( "Encountered an exception while processing Order {$orderId}: \n" . (string) $exception );
    }

    public function logApiError($params = null, $apiUrl, $curlError, $responseCode)
    {
        $this->critical( "Encountered an exception while sending an API request. Here is the API url: {$apiUrl}" );
        $this->critical( "Encountered an exception while sending an API request. Here are the parameters: " );
        $this->critical(print_r($params,true));
        $this->critical( "Encountered an exception while sending an API request. Here is the response code: " );
        $this->critical(print_r($responseCode,true));
        $this->critical( "Encountered an exception while sending an API request. Here is the exception: " );
        $this->critical(print_r($curlError,true));
    }
}

