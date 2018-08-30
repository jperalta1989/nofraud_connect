# NoFraud Connect (M2)

Integrates NoFraud's post-payment-gateway API functionality into Magento 2.

## Sections

* [ Getting Started ](#markdown-header-getting-started)
    * [ Troubleshooting ](#markdown-header-troubleshooting)
* [ Flow of Execution (Checkout) ](#markdown-header-flow-of-execution-checkout)
* [ Flow of Execution (Updating Orders Marked for Review) ](#markdown-header-flow-of-execution-updating-orders-marked-for-review)
* [ Admin Panel Special Configuration ](#markdown-header-admin-panel-special-configuration)

## Getting Started

### Installation
----------------

Just copy to the appropriate folder and run `php magento setup:upgrade`.

```
git clone git@bitbucket.org:razoyo/mage2-module-nofraud.git
cp -r mage2-module-nofraud/app/ ~/current
php ~/current/bin/magento setup:upgrade
```

### Configuration
-----------------

### Troubleshooting
-------------------

All logging happens in `<magento_root_folder>/var/log/nofraud_connect/info.log`

## Flow of Execution (Checkout)

### Observer\SalesOrderPaymentPlaceEnd
--------------------------------------

As far creating new NoFraud transaction records, this class is where it all happens.

The observer listens for the `sales_order_payment_place_end` event, which dispatches after a payment is placed (`\Magento\Sales\Model\Order\Payment->place()`), and makes available the associated `Payment` object.

> NOTE: Listening to this particular event is largely out of my initial deference to the original M1 module, and in light of new information, listening to a later event may reduce complexity (see below). // LINK

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
        > This condition is essentially a compatibility measure for Authorize.net; see below for more detail. //LINK
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

This class contains simple "getter" functions for each Admin Config setting, along with a few wrapper functions which compare provided input against Config values and return a boolean.

### Api\RequestHandler 
----------------------

This class contains only three public functions:

#### RequestHandler public function build( $payment, $order, $apiToken )

Builds the body (a JSON object) for a `POST` request to the NoFraud API.

This function is only involved in creating new NoFraud transaction records during checkout (`\NoFraud\Connect\Observer\SalesOrderPaymentPlaceEnd`).

The full object model this function can build resembles the following (not all values are always present, and keys with empty non-numeric values are removed).
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

By default, this function handles `POST` requests prepared by `build(...)`. If `$statusRequest` is truthy, then a `GET` request is sent instead, and `$params` is assumed to contain only an existing NoFraud Transaction ID and the NoFraud API token.

#### RequestHandler public function getTransactionStatus( $nofraudTransactionId, $apiToken, $apiUrl )

A readability wrapper for retrieving the current status of a NoFraud transaction record via `send(...)`.

This function is currently only called from `\NoFraud\Connect\Cron\UpdateOrdersUnderReview`.

#### RequestHandler Protected Functions

The remaining functions in this class almost all pertain to getting or formatting data from the `Order` and `Payment` objects passed into `build(...)`.

The following two are worth mentioning:

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

#### RequestHandler protected function buildParamsAdditionalInfo( $payment )

This function accounts for the arbitrary values some payment processors place in the `Payment`'s `additional_information` column.

For example, PayPal Payments Pro and Braintree both place detailed credit card information in `additional_information` rather than the correct corresponding columns Magento already provides (`cc_last4`, `cc_avs_status`, etc.).

Unfortunately, this means this function will need to be kept up-to-date with any changes made to each payment processor's own implementation.

### Api\ResponseHandler 
-----------------------

This class is currently only responsible for building Status History Comments for `Order` objects, based on the `$resultMap` returned from `RequestHandler->send(...)`.

It has two public functions.

#### ResponseHandler public function buildComment( $resultMap )

Responsible for building the initial Status History Comment applied to `Order`s at checkout. Has conditional logic to handle the different NoFraud response types, as well as API calls which resulted in HTTP client errors.

#### ResponseHandler public function buildStatusUpdateComment( $resultMap )

Responsible for building comments to be applied when a "review" transaction's status has been updated to "pass" or "fail". This function does not contain the special exhaustive variant messages from `buildComment(...)`, so as to avoid adding new Status History Comments unless a proper update has been retrieved from NoFraud.

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

A nested array, however, results in grouped choices:

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
            'hosted_pro' => [
                'value' => 'hosted_pro',
                'label' => 'Payment by cards or by PayPal account',
            ],
        ],
    ],

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

The Magento core function `\Magento\Payment\Helper\Data->getPaymentMethodList(true, true, true)`
has a bug which results in offline payment methods being omitted from the output. The [bugfix](https://github.com/magento/magento2/issues/13460#issuecomment-388584826) is inexplicably [unavailable in M2.2](https://github.com/magento/magento2/issues/13460#issuecomment-388584826).

I resorted to using the simpler `\Magento\Payment\Model\Config->getActiveMethods()`; however, this function also fails to retrieve a complete list. It's possible the payment processors which turn up missing have been implemented incorrectly and may need to be specially accounted for.

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

## Global vs Frontend Event Scope

#### 

## Matters of Opinion

### Which Event to Observe

### Separation of Concerns

### Code Style
--------------

The code itself is a little verbose with regards to line count, but it's in the interest of keeping things dumb, lazy, and (if not always readable) comprehensible (and hopefully therefore easy to change). For example, wherever possible and practical, nested conditional prerequisites for a function call are avoided in favor of sequential "if (condition) then (stop execution)" statements which precede that function call.

Most functions in the module which rely on outside information require it to be passed in, so at the point of execution, much of the code is actually dedicated to _preparing_ to call the comparitively few functions which result in real record modifications.

Another large chunk, as described above, is dedicated to stopping execution at the earliest possible point (given that all this happens in the course of the page load after clicking "Place Order").

I want to mention all this now, because I think the following exemplifies these points, and similar patterns will be apparent throughout the rest of the code:
