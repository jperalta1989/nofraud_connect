# NoFraud Connect (M2)

Integrates NoFraud's post-payment-gateway API functionality into Magento 2.

## Sections

* [ Getting Started ](#markdown-header-getting-started)
    * [ Installation ](#markdown-header-installation)
    * [ Configuration ](#markdown-header-configuration)
    * [ Troubleshooting ](#markdown-header-troubleshooting)
    * [ Known Issues ](#markdown-header-known-issues)
    * [ Features to be Implemented ](#markdown-header-features-to-be-implemented)
* [ Flow of Execution (Checkout) ](#markdown-header-flow-of-execution-checkout)
    * [ Observer\SalesOrderPaymentPlaceEnd ](#markdown-header-observersalesorderpaymentplaceend)
    * [ Helper\Config ](#markdown-header-helperconfig)
    * [ Api\RequestHandler ](#markdown-header-apirequesthandler)
    * [ Api\ResponseHandler ](#markdown-header-apiresponsehandler)
    * [ Logger\Logger ](#markdown-header-loggerlogger)
* [ Flow of Execution (Updating Orders Marked for Review) ](#markdown-header-flow-of-execution-updating-orders-marked-for-review)
    * [ Cron\UpdateOrdersUnderReview ](#markdown-header-cronupdateordersunderreview)
    * [ etc/crontab.xml ](#markdown-header-etccrontabxml)
* [ Admin Panel Special Configuration ](#markdown-header-admin-panel-special-configuration)
    * [ Model\Config\Source\EnabledPaymentMethods ](#markdown-header-modelconfigsourceenabledpaymentmethods)
    * [ etc/di.xml ](#markdown-header-etcdixml)
    * [ Helper\Data ](#markdown-header-helperdata)
* [Dispatch Event Considerations](#markdown-header-dispatch-event-considerations)
    * [ Global vs. Frontend Scope ](#markdown-header-global-vs-frontend-scope)
    * [ Potential for Duplicate API Calls ](#markdown-header-potential-for-duplicate-api-calls)
* [Matters of Opinion](#markdown-header-matters-of-opinion)
    * [ Code Style ](#markdown-header-code-style)
    * [ Separation of Concerns ](#markdown-header-separation-of-concerns)

## Getting Started

### Installation
----------------

Just copy to the appropriate folder and run `php magento setup:upgrade`.

```
git clone git@bitbucket.org:razoyo/mage2-module-nofraud.git
cp -r mage2-module-nofraud/app/ ~/current
php ~/current/bin/magento setup:upgrade
```

From the COMMAND LINE using Composer:
```
1. Update composer to require the "nofraud/connect" package with the command: $ composer require nofraud/connect dev-master

2. To enable the module, run the command: $ bin/magento module:enable NoFraud_Connect

3. Then run setup:upgrade to install the necessary updates, with the command: $ bin/magento setup:upgrade

4. If a production environment - re deploy the static content and run the di compiler
```

### Configuration
-----------------

### Troubleshooting
-------------------

All logging happens in `<magento_root_folder>/var/log/nofraud_connect/info.log`

### Known Issues
----------------

* [ Cron job is defined and tested but doesn't run on its own ](#markdown-header-etccrontabxml)
* [ "Screened Payment Methods" has the expected effect, but does not show all enabled payment options as choices ](#markdown-header-difficulty-returning-array-of-all-enabled-payment-methods)

### Features to be Implemented
------------------------------

* Ability to auto-refund orders based on NoFraud API response

## NoFraud API Basics

There are two type of requests used in this module:

* `POST` requests, to create new NoFraud transaction records
* `GET` requests, to retreive the status of an existing NoFraud transaction record

### Creating New Records
------------------------

Posting a JSON decription of a transaction will create a new record, and will return
a small JSON object:

```json
{
  "id":"16f235a0-e4a3-529c-9b83-bd15fe722110",
  "decision":"pass"
}
```

An additional `message` key will be present for a "fail" decision, 
but this key is never used by the module.

```json
{
  "id":"16f235a0-e4a3-529c-9b83-bd15fe722110",
  "decision":"fail",
  "message":"Declined"
}
```

### Getting the Status of Existing Records
-------------------------------------------

A `GET` request sent to `https://api.nofraud.com/status/:nf_token/:order_id` will return a similar response:

```json
{
  "id":"16f235a0-e4a3-529c-9b83-bd15fe722110",
  "decision":"pass"
}
```

The `:order_id` can either be the unique NoFraud transaction `id` provided in the original API response,
or the associated Magento Order `increment_id`. Either one can be used interchangeably.

### Errors
----------

If either 

* improperly formatted or insufficient data is posted to the API, or
* a status is requested for an invalid transaction ID

a JSON object will be returned, containing an array of one or more error message strings.

```json
{
  "Errors":[
    "Error Message 1.",
    "Error Message 2."
  ]
}
```

## User Experience

### Customer
------------

As this module implements post-payment-gateway functionality, the customer checkout experience should remain unchanged.

### Site Admin
--------------

At the end of the checkout process, information about the transaction is posted to the NoFraud API. 
In all cases, the response from NoFraud is attached to the `Order` in question as a Status History Comment.
This is displayed on the Order's admin page, and provides a link directly to the associated record on the NoFraud website.

Depending on the decision returned by NoFraud ("pass", "fail", or "review"), the `Order` in question can also automatically be placed in a custom
status (for example, "On Hold", "Fraud Detected", "Cancelled", etc.). A custom status can also be configured for the 
case that NoFraud returns an error message.

All of the above can restricted to apply only to certain payment methods. 
It's also possible to restrict processing to `Order`s with a certain status 
at the time of execution (for example, if an order is already "Complete", it can be ignored).

Orders placed under review will be updated in NoFraud's database to a "pass" or "fail" at a later time. 
The module will periodically check the status of such orders, and once a final
"pass" or "fail" decision is received from the NoFraud API, the Order's status in Magento
will be updated according to the same configuration options described above.

#### Auto-Refund

While not yet implemented, Orders should additionally be able to be automatically 
refunded based on the conditions decribed above.

## Flow of Execution (Checkout)

### Observer\SalesOrderPaymentPlaceEnd
--------------------------------------

As far creating new NoFraud transaction records, this class is where it all happens.

The observer listens for the `sales_order_payment_place_end` event, which dispatches after a payment is placed 
(`\Magento\Sales\Model\Order\Payment->place()`), and makes available the associated `Payment` object.

> NOTE: Listening to this particular event is largely out of my initial deference to the original M1 module, 
and in light of new information, listening for a later event may reduce complexity. ([ see below ](#markdown-header-potential-for-duplicate-api-calls))

#### What Happens During Execution:

1. If the transaction should be ignored, then:
    1. Do nothing.
1. Else:
    1. Post the transaction's information to the NoFraud API;
    1. Add a comment to the Order, depending on the API response;
    1. Modify the status of the Order, depending on the API response and the module's configuration;
    1. Save the Order.

#### The Actual Flow of Execution:

1. If the module is disabled, then:
    1. Stop execution.
1. Get the `Payment` from the `Observer`;
1. If the Payment should be ignored, then:
    1. Stop execution.
1. If the Payment does not have a transaction ID AND is not an offline payment method, then:
    1. Stop execution.
    > NOTE: This condition is essentially a compatibility measure for Authorize.net. ([ see below ](#markdown-header-potential-for-duplicate-api-calls))
1. Get the `Order` from the `Payment`;
1. If the Order should be ignored, then:
    1. Stop execution.
1. Get the NoFraud API Token from Config;
1. Get the appropriate API URL, depending on the "Sandbox Mode" setting in Config;
1. Prepare the body of the NoFraud API request, from the `Payment` and `Order` objects;
1. Send the API request and get the response;
1. Add a comment to the `Order`, depending on the response (good or bad);
1. If the response was good (no API server errors), then:
    1. Update the status of the `Order`, depending on the "Custom Order Statuses" setting in Config;
1. Save the `Order`.

This all relies on the following classes:

### Helper\Config
-----------------

This class contains simple "getter" functions for each Admin Config setting, along with a few wrapper functions 
which compare provided input against Config values and return a boolean.

### Api\RequestHandler 
----------------------

This class contains only three public functions:

#### RequestHandler public function build( $payment, $order, $apiToken )

Builds the body (a JSON object) for a `POST` request to the NoFraud API.

This function is only involved in creating new NoFraud transaction records during checkout (`\NoFraud\Connect\Observer\SalesOrderPaymentPlaceEnd`).

The full object model this function can build resembles the below example (not all values are always present, and keys with empty non-numeric values are removed).
The full model accepted by the NoFraud API is [described here](https://portal.nofraud.com/pages/developer-documentation#1.4).

```
{
  "nf-token": "API-KEY-EXAMPLE",
  "amount": "100.00",
  "shippingAmount": "20.00",
  "currency_code": "USD",
  "customer": {
    "email": "someperson@gmail.com"
  },
  "order":{
    "invoiceNumber": "1123581321"
  },
  "payment": {
    "creditCard": {
      "last4": "1111",
      "cardType": "Visa",
      "cardNumber": "4111111111111111",
      "expirationDate": "0919",
      "cardCode": "999",
    }
  },
  "billTo": {
    "firstName": "Some",
    "lastName": "Person",
    "company": "Some Company",
    "address": "1234 Main St Apt #123",
    "city": "New York",
    "state": "NY",
    "zip": "11001",
    "country": "US",
    "phoneNumber": "1112223333"
  },
  "shipTo": {
    "firstName": "Another",
    "lastName": "Person",
    "company": "Another Company",
    "address": "4321 Ave A",
    "city": "Paris",
    "state": "TX",
    "zip": "77000",
    "country": "US"
  },
  "customerIP": "127.0.0.1",
  "avsResultCode": "U",
  "cvvResultCode": "1",
  "lineItems": [
    {
      "sku": "12345",
      "name": "Example Product 1",
      "price": 24.95,
      "quantity": 3
    },
    {
      "sku": "23456",
      "name": "Example Product 2",
      "price": 179.49,
      "quantity": 1
    }
  ],
  "userFields": {
    "magento2_payment_method": "payflowpro"
  }
}
```

#### RequestHandler public function send( $params, $apiUrl, $statusRequest = false )

Sends requests to the NoFraud API and returns a `$resultMap` (see Protected Functions).

By default, this function handles `POST` requests prepared by `build(...)`. If `$statusRequest` is truthy, then a `GET` request is sent instead, 
and `$params` is assumed to contain only an existing NoFraud Transaction ID and the NoFraud API token.

#### RequestHandler public function getTransactionStatus( $nofraudTransactionId, $apiToken, $apiUrl )

A readability wrapper for retrieving the current status of a NoFraud transaction record via `send(...)`.

This function is currently only called from `\NoFraud\Connect\Cron\UpdateOrdersUnderReview`.

#### Default AVS and CVV Codes

```php
<?php

const DEFAULT_AVS_CODE = 'U';
const DEFAULT_CVV_CODE = 'U';
```

An AVS or CVV code of `"U"` indicates "information unavailable". If the proper codes cannot be retreived at checkout,
then these are the fallback codes sent to NoFraud (if nothing is sent, an error will occur).

#### RequestHandler Protected Functions

The remaining functions in this class almost all pertain to getting or formatting data from the `Order` and `Payment` objects passed into `build(...)`.

The following few are worth mentioning:

#### RequestHandler protected function buildResultMap( $curlResult, $ch )

Takes a curl result and connection and returns an array resembling the model below (keys with empty non-numeric values are removed).

Used in several places in the module, and referred to as `$resultMap` throughout.

```
[
    'http' => [
        'response' => [
            'body' => $responseBody,
            'code' => $responseCode,
            'time' => $responseTime,
        ],
        'client' => [
            'error' => $curlError,
        ],
    ],
]
```

#### RequestHandler protected function formatCcType( $code )

NoFraud expects the `cardType` field to contain the brand name of the credit card in word form.
However, payment processors only provide two-letter codes representing each brand. The protected variable
`$ccTypeMap` contains a hash of several code-to-brand-name translations, but the list is likely not exhaustive,
and new codes can simply be added here.

#### RequestHandler protected function buildParamsAdditionalInfo( $payment )

This function accounts for the arbitrary values some payment processors place in the `Payment`'s `additional_information` column.

For example, PayPal Payments Pro and Braintree both place detailed credit card information in `additional_information` rather 
than in the correct corresponding columns Magento already provides (`cc_last4`, `cc_avs_status`, etc.).

Unfortunately, this means this function will need to be kept up-to-date with any changes made to each payment processor's own implementation.

### Api\ResponseHandler 
-----------------------

This class is currently only responsible for building Status History Comments for `Order` objects, 
based on the `$resultMap` returned from `RequestHandler->send(...)`.

It has two public functions.

#### ResponseHandler public function buildComment( $resultMap )

Responsible for building the initial Status History Comment applied to `Order`s at checkout. 
Has conditional logic to handle the different NoFraud response types, as well as API calls which resulted in HTTP client errors.

#### ResponseHandler public function buildStatusUpdateComment( $resultMap )

Responsible for building comments to be applied when a "review" transaction's status has been updated to "pass" or "fail". 
This function does not contain the special exhaustive variant messages from `buildComment(...)`, 
so as to avoid adding new Status History Comments unless a proper update has been retrieved from NoFraud.

### Logger\Logger 
-----------------

A simple custom logger used throughout.

It outputs to `<magento_root_folder>/var/log/nofraud_connect/info.log`, and is configured by the following files:

```
Logger/Logger.php
Logger/Handler/Info.php
etc/di.xml
```

It also has two public functions:

#### Logger public function logTransactionResults( $order, $payment, $resultMap )

For logging the results of `POST` requests sent to the NoFraud API.

#### Logger public function logFailure( $order, $exception )

For logging Exceptions thrown when failing to modify an `Order` model, along with the `Order`'s ID number.

## Flow of Execution (Updating Orders Marked for Review)

### Cron\UpdateOrdersUnderReview
--------------------------------

When a new transaction is posted to the NoFraud API, a decision is returned ("pass", "fail", or "review"),
along with a unique transaction ID.

Transactions marked for review will eventually be updated to "pass" or "fail" in the NoFraud database, and
these changes need to be reflected in the Magento so the appropriate `Order` status updates can be applied.

While this cron job is ultimately concerned with updating `Order` models, there is no easy way (after the fact)
to identify which Orders have been marked for review. Rather than create a new table to keep track of this,
I decided to use the `additional_information` field in the `Payment` object associated with the `Order`.

So, during checkout, if a decision is received from NoFraud, then both the decision code ("pass", "fail", or "review")
and the unique NoFraud transaction ID are stored in the `Payment`'s `additional_information['nofraud_response']` key.

With this in place, the cron job proceeds as follows (in terms of changes to the database):

1. Get all `Payment`s where `additional_information` contains a key/value `['nofraud_decision' => 'review']`;
1. If no `Payment`s are marked for review, then:
    1. Stop execution.
1. For each `Payment` marked for review:
    1. Get the current NoFraud decision from the NoFraud API;
    1. If a good response was received (no server/client errors), then:
        1. Get the `Order` from the `Payment`;
    1. If the NoFraud decision has been updated to "pass" or "fail", then:
        1. Update `Order` Status according to Admin Config;
        1. Add a Status History Comment to the `Order`;
        1. Update `Payment`'s `additional_information['nofraud_response']` key;
        1. Save the Order.

#### PaymentRepository and SearchCriteriaBuilder

I found a neat way to build database queries in Magento before actually firing them, and I decided to leverage that.
I know that Magento does lazy database queries by default, so there may be no actual performance benefit to using these classes,
but I find it makes it clearer what's going on.

The cron's constructor takes a:

* `\Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder`, and a
* `\Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository`

The `$criteriaBuilder` does exactly what it sounds like. After adding the appropriate search filters,
calling `$criteriaBuilder->create()` returns a `\Magento\Framework\Api\SearchCriteria` object.

```php
<?php

$criteria = $this->criteriaBuilder
    ->addFilter(
        'additional_information',
        '%nofraud_decision___review%',
        'like'
    )->create();
```

This can be passed to a Repository's `getList()` function, which will return a corresponding variation of `\Magento\Framework\Api\SearchResult`.
Calling `$searchResult->getItems()` will return an actual `Array` containing the objects returned from the database (in this case a `\Magento\Sales\Api\Data\OrderPaymentInterface[]`),

```php
<?php

$searchResult = $this->paymentRepository->getList( $criteria );
$paymentsUnderReview = $searchResult->getItems();
```

#### Explaining the Search Criteria

The search criteria translates to

```sql
SELECT * FROM sales_order_payment WHERE additional_information LIKE '%nofraud_decision___review%'
```

I figured matching the plain text in the database column would be better than loading every `Payment` object, 
then calling `getAdditionalInformation()` on each one of them, etc.

The `additional_information` column is in plain text JSON format. An example column value containing a NoFraud decision looks like this:

```json
{
   "method_title":"Credit Card (Braintree)",
   "avsPostalCodeResponseCode":"M",
   "avsStreetAddressResponseCode":"M",
   "cvvResponseCode":"M",
   "processorAuthorizationCode":"JLTB38",
   "processorResponseCode":"1000",
   "processorResponseText":"Approved",
   "cc_number":"xxxx-1111",
   "cc_type":"Visa",
   "nofraud_response":{
      "nofraud_decision":"pass",
      "nofraud_transaction_id":"f086396d-b948-5070-983c-f88d04469bf9"
   }
}
```

So, if a transaction was marked for review, the string `"nofraud_decision":"review"` will occur somewhere in the column.
In SQL, an underscore represents any single character, so this will be matched by `'%nofraud_decision___review%'`.
It might be less specific, but I think it looks nicer than `'%\"nofraud\_decision\":\"review\"%'`. 

```php
<?php

$criteria = $this->criteriaBuilder
    ->addFilter(
        'additional_information',
        '%nofraud_decision___review%',
        'like'
    )->create();
```

### etc/crontab.xml
-------------------

While I've configured the job to run every hour, I haven't gotten it to run on its own on the test cell.
I figured this would be the easiest problem to solve, so I focused on testing the actual content of the cron job instead.

I do know there are differences between Magento's "default" and "index" cron groups. I don't know why either would
interfere with an hourly job. It may make sense to define a NoFraud cron group in any case.

## Admin Panel Special Configuration

### Model\Config\Source\EnabledPaymentMethods
-------------------------------------------------

This class only defines a single public function, and serves as the Source Model for the "Screened Payment Methods" Config field.

#### EnabledPaymentMethods public function toOptionArray()

The way this array is constructed is less important than the format of the output.

For example, an array like the following would result in a flat list of choices:

```php
<?php

[
    'braintree' => [
        'value' => 'braintree',
        'label' => 'Credit Card (Braintree)',
    ],

    'authorizenet_directpost' => [
        'value' => 'authorizenet_directpost',
        'label' => 'Credit Card Direct Post (Authorize.net)',
    ],
]

```

A nested entry, however, results in a labeled group of choices:

```php
<?php

[
    'paypal' => [
        'label' => 'PayPal', // <- group 'label'
        'value' => [         // <- group 'value' (array of choices in the group)
            'paypal_billing_agreement' => [
                'value' => 'paypal_billing_agreement',
                'label' => 'PayPal Billing Agreement',
            ],
            'payflow_express_bml' => [
                'value' => 'payflow_express_bml',
                'label' => 'PayPal Credit',
            ],
        ],
    ],

    'authorizenet_directpost' => [
        'value' => 'authorizenet_directpost',
        'label' => 'Credit Card Direct Post (Authorize.net)',
    ],
]

```

#### Difficulty Returning Array of all Enabled Payment Methods

The Magento core function `\Magento\Payment\Helper\Data->getPaymentMethodList(...)`
has a bug which results in offline payment methods being omitted from the output. 
The [bugfix](https://github.com/magento/magento2/issues/13460#issuecomment-388584826) is inexplicably 
[unavailable in M2.2](https://github.com/magento/magento2/issues/13460#issuecomment-388584826).

I resorted to using the simpler `\Magento\Payment\Model\Config->getActiveMethods()`; however, this function also fails to retrieve a complete list. 
It's possible the payment processors which turn up missing have been implemented incorrectly and may need to be specially accounted for.

### etc/di.xml
--------------

Contains a node related to obscuring the API Token field in the Config panel.

```xml
<config>
    <type name="Magento\Config\Model\Config\TypePool">
        <arguments>
            <argument name="sensitive" xsi:type="array">
                <item name="nofraud_connect/general/api_token" xsi:type="string">1</item>
            </argument>
        </arguments>
    </type>
</config>
```

### Helper\Data
---------------

This class is only defined because the Magento Admin panel will throw a fit if it's not.

## Dispatch Event Considerations

### Global vs. Frontend Scope
-----------------------------

The `etc/events.xml` file resides in the global scope due to inconsistency between payment processors; some do not dispatch their events in the Frontend scope.

### Potential for Duplicate API Calls
-------------------------------------

The `sales_order_payment_place_end` event can fire an indeterminate amount of times, as demonstrated by Authorize.net.
Because of this, `Observer\SalesOrderPaymentPlaceEnd` contains conditional logic to ensure that duplicate API calls 
(and therefore duplicate NoFraud records) are not created.

The first time that Authorize.net causes `..payment_place_end` to fire, the transaction has not been processed by their servers, 
and the `Payment` object available in Magento contains incomplete information.
By the second time, the `Payment` has been populated with complete information, 
including the Authorize.net transaction ID (stored in the `last_trans_id` column).

Thus, `Observer\SalesOrderPaymentPlaceEnd` does not process the transaction unless a `last_trans_id` is present, which solves the problem in Authorize.net's case. 
While it's not likely, it is possible that a payment processor could fire `...payment_place_end` more than once, 
with the `Payment` object fully populated on the first occurence.
This would render the conditional statement useless, resulting in duplicate API calls and duplicate records.

In light of this, it may be worth the time to have the observer listen for an event further down the checkout pipeline, 
which is less likely to be affected by payment processors (for example, `sales_order_place_after` or `checkout_submit_all_after`).

## Matters of Opinion

### Code Style
--------------

The code itself is a little verbose with regards to line count, but it's in the interest of keeping things dumb, lazy, and 
(if not always readable) comprehensible (and hopefully therefore easy to change). For example, wherever possible and practical,
nested conditional prerequisites for a function call are avoided in favor of sequential "if (condition) then (stop execution)" 
statements which precede that function call.

Most functions in the module which rely on outside information require it to be passed in, so at the point of execution, much 
of the code is actually dedicated to _preparing_ to call the comparitively few functions which result in real record modifications.

Another large chunk, as described above, is dedicated to stopping execution at the earliest possible point (given that the main execution 
happens in the course of the page load after clicking "Place Order").

### Separation of Concerns
--------------------------

Originally, I wanted all API-related information to reside within the `Api\RequestHandler` class.
However, there are now two places in the code with this full conditional statement typed out:

```php
<?php

// Use the NoFraud Sandbox URL if Sandbox Mode is enabled in Admin Config:
//
$apiUrl = $this->apiUrl->whichEnvironmentUrl();
```

I've noticed other modules have their API urls (both production and test) configurable from the Admin panel.
If NoFraud's url's were similarly stored in the Config, the above block could be simplified to one function call:

```php
<?php

// Get the API URL:
//
$apiUrl = $this->configHelper->getApiUrl();
```

#### Why Not Inject Helper\Config as a Dependency of Api\RequestHelper?

Since `Observer\SalesOrderPaymentPlaceEnd` and `Cron\UpdateOrdersUnderReview` depend on both `Helper\Config` and `Api\RequestHelper`, 
that would mean that `Helper\Config` would be instantiated twice in the course of executing single functions, which made me vomit a little.
