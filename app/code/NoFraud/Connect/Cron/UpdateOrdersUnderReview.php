<?php
namespace NoFraud\Connect\Cron;

class UpdateOrdersUnderReview
{
    public function __construct(
        \Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder,
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Api\RequestHandler $requestHandler,
        \NoFraud\Connect\Api\ResponseHandler $responseHandler,
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->requestHandler = $requestHandler;
        $this->responseHandler = $responseHandler;
        $this->configHelper = $configHelper;
        $this->logger = $logger;
    }

    public function execute()
    {
        $this->logger->info( "starting cron: update_orders_under_review" ); // LOGGING

        try {

            // Get all Payments where AdditionalInformation contains a key/value ['nofraud_decision' => 'review']:
            //
            $criteria = $this->criteriaBuilder
                ->addFilter(
                    'additional_information',
                    '%nofraud_decision___review%',
                    'like'
                )->create();
            $searchResult = $this->paymentRepository->getList( $criteria );
            $paymentsUnderReview = $searchResult->getItems();

            // If no Payments are marked as under review, then do nothing:
            //
            if ( empty($paymentsUnderReview) ){
                $this->logger->info( "no orders currently under review" ); // LOGGING
                $this->logger->info( "finishing cron: update_orders_under_review" ); // LOGGING
                return;
            }


            // Get appropriate Api url:
            //
            $apiUrl = $this->configHelper->getSandboxMode() ?
                $this->requestHandler::SANDBOX_URL          :
                $this->requestHandler::PRODUCTION_URL       ;

            // Get Api credentials
            //
            $apiToken = $this->configHelper->getApiToken();


            foreach ( $paymentsUnderReview as $payment ){

                // Get NoFraud Transaction ID from Payment's `additional_information['nofraud_response']` key:
                //
                $nofraudResponse = $payment->getAdditionalInformation('nofraud_response');

                // Skip Payments with bad data (mostly for debugging; shouldn't be possible in production): // DEBUG
                //
                if ( empty($nofraudResponse) || empty($nofraudResponse['nofraud_transaction_id']) ){
                    continue;
                } else {
                    $nofraudTransactionId = $nofraudResponse['nofraud_transaction_id'];
                }

                // Send a GET request to the NoFraud API
                // (Get the current NoFraud decision associated with the Transaction ID):
                //
                $resultMap = $this->requestHandler->getTransactionStatus( $nofraudTransactionId, $apiToken, $apiUrl );
                $decision = $resultMap['http']['response']['body']['decision'] ?? NULL ;
                
                // If NoFraud decision has been updated to 'pass' or 'fail', then
                // make appropriate changes to the Order and Payment objects.
                //
                // If not, log the reason why and move on.
                //
                if ( in_array( $decision, ['pass','fail','review'] ) ){

                    // Get associated Order and its ID
                    //
                    $order = $payment->getOrder();
                    $orderId = $order->getIncrementId();

                    switch ( $decision ){

                        case 'review':
                            $this->logger->info( "{$orderId}: no change: still under review" ); // LOGGING
                            break;

                        default:
                            $this->logger->info( "{$orderId}: got final decision: '{$decision}'" ); // LOGGING

                            // Update Order Status according to Admin Config
                            //
                            $newStatus = $this->configHelper->getCustomStatusConfig($decision);
                            if ( !empty($newStatus) ){
                                $this->logger->info( "{$orderId}: updating Order Status to '{$newStatus}'..." ); // LOGGING

                                $order->setStatus($newStatus);
                            }

                            // Add a Status History Comment to the Order
                            //
                            $comment = $this->responseHandler->buildStatusUpdateComment( $resultMap );
                            if ( !empty($comment) ){
                                $this->logger->info( "{$orderId}: adding Status History Comment..." ); // LOGGING
                                $order->addStatusHistoryComment($comment);
                            }

                            // Update Payment['additional_information']['nofraud_response']
                            // 
                            $this->logger->info( "{$orderId}: updating Payment's 'additional_information' column..." ); // LOGGING

                            $nofraudResponse['nofraud_decision'] = $decision;
                            $payment->setAdditionalInformation( 'nofraud_response', $nofraudResponse );

                            $payment->save();
                            $order->save();

                            $this->logger->info( "{$orderId}: success" ); // LOGGING
                            break;
                    }

                } else {
                    $this->logger->info( "ERROR: cron: update_orders_under_review: " . json_encode($resultMap) ); // LOGGING
                }

            }

            $this->logger->info( "finishing cron: update_orders_under_review" ); // LOGGING

        } catch ( \Exception $message ) {
            $this->logger->info( "ERROR: cron: update_orders_under_review: " . ((string) $message) );
        }
    }
}
