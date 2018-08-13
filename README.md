# NoFraud Connect (M2)

## Phase One Implementation
---------------------------

Currently, as NoFraud is interested only in post-gateway vetting, the module has a single observer listening for `sales_order_place_after` (trimmed down from the original module's four observers).

The Oberver receives a `\NoFraud\Connect\Api\RequestHandler` and `ResponseHandler` on construction.

It also receives a Config reader ( `\NoFraud\Connect\Helper\Config` ) and a custom logger `\NoFraud\Connect\Logger\Logger`, though no logging is currently implemented.

Execution currently looks like this:

1. If module is disabled from Admin Config, do nothing.
2. Get the Order from the Event ( `\Magento\Sales\Model\Order` ).
3. Get the Payment from the Order ( `\Magento\Sales\Model\Order\Payment` ).
4. Build the NoFraud API request from the Payment and Order objects ( `$this->requestHandler->build()` ).
5. Get the appropriate API URL from `RequestHandler` ( e.g. `$this->requestHandler::SANDBOX_URL` ) based on Admin Config setting.
6. Send the request ( `$this->requestHandler->send()` ) and get the response.
7. For "review" or "fail" responses from NoFraud, mark the order as "Fraud Detected" in Magento ( `$order->setStatus( \Magento\Sales\Model\Order::STATUS_FRAUD )` ).
8. For _all_ responses from NoFraud, add an informative StatusHistoryComment to the Order ( `$order->addStatusHistoryComment($comment)` );
9. Finally, save the Order.
