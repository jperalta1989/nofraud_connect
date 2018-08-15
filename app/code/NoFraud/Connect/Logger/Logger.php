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
        // todo: Place info logs and stack traces in separate files? Will require multiple Logger classes.
        $orderId = $order->getIncrementID();
        $this->critical( "Encountered an exception while processing Order {$orderId}: \n" . (string) $exception );
    }
}

