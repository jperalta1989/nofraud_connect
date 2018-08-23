<?php
namespace NoFraud\Connect\Logger;

class Logger extends \Monolog\Logger
{
    public function logTransactionResults( $order, $payment, $resultMap )
    {
        $orderLog['id'] = $order->getIncrementID();

        $paymentLog['method'] = $payment->getMethod();

        $info = [
            'order' => $orderLog,
            'payment' => $paymentLog,
            'api_result' => $resultMap,
        ];

        $this->info( json_encode($info) );
    }

    public function logFailure( $order, $exception )
    {
        $orderId = $order->getIncrementID();
        $this->critical( "Encountered an exception while processing Order {$orderId}: \n" . (string) $exception );
    }
}

