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

## TODO Considerations (per William)
------------------------------------

### Queued API Calls

Queueing API calls should be trivial to implement. As purchases are made, their corresponding nofraud API call objects could be saved as Magento Models ( e.g. `\NoFraud\Connect\Model\ApiCall` ):

> The ApiCall table columns could look like `| transaction_id | json_object |`, encrypting the whole API call, or could be modeled after the NoFraud API request object ( `| customer | payment | bill_to | ship_to | etc...` ), encrypting only certain values. I imagine the first option would be more performant.

The cron job could look like:

  1. If the module is disabled, do nothing.
  2. If the NoFraud API is unavailable, do nothing.
  3. Get the first `ApiCall` (lowest transaction ID).
  3. Use the `ApiCall` transaction ID to get the transaction status from the NoFraud API.
  4. If a NoFraud assessment already exists, remove the `ApiCall` from the queue.
  5. Else, decrypt the API request object, make and process the call, and remove the `ApiCall` from the queue.

#### Performance Implications

In the current implementation, the NoFraud API could be unavailable at the time of purchase, which could (even if rarely) present a hole for fraudulent transactions to pass through.

By queueing API calls, we can:

  * ...ensure that NoFraud is available before sending the request.
  * ...ensure that a proper response was received before modifying the Order object.

#### Security Implications

Even if sensitive data is encrypted by the module, this doesn't mean that the server it's running on is PCI compliant. Ultimately, it would be NoFraud's decision as to whether this is an acceptable level of risk.

A possible workaround would be to forgo the collection of credit card information, given that post-gateway NoFraud assessments do not require any credit card information per se (only AVS and CVV result codes from the payment processor).

Incidentally, payment processors do not make credit card information readily available in M2 to begin with ( my tests with Authorize.net indicate anything other than result codes will need to be specially intercepted from inside their own module, or may even be completely unavailable due to the introduction of Accept.js ).

In its current implementation, the module attempts (but is unlikely) to gather CC info. If NoFraud approves of skipping credit card information altogether, this addition could be made without serious PCI compliance issues. However, it's my guess that they would prefer as much possible information to be gathered, even if not required, and would be less concerned about the performance and stability issues this may cause.

#### Alternative

A simpler, but less user-friendly, alternative could be to check the status of NoFraud before each transaction, and prevent the customer from proceeding to checkout unless NoFraud is available.

This could be a togglable option, allowing users to accept the risk of NoFraud being unavailable if they'd prefer not to potentially harm the user experience.
