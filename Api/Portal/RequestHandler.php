<?php
 
namespace NoFraud\Connect\Api\Portal;
 
use org\bovigo\vfs\DirectoryIterationTestCase;

class RequestHandler extends \NoFraud\Connect\Api\Request\Handler\AbstractHandler
{
    const TRANSACTION_STATUS_ENDPOINT = 'status_by_invoice';

    public function __construct(
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * @param string $apiUrl | Nofraud API Url
     * @param string $orderId | Order Id
     * @param string $apiToken | NoFraud API Token
     *
     * @return array|bool
     */
    public function build( $apiUrl, $orderId, $apiToken )
    {
        $params = [];
        $params['nf_token'] = $apiToken;
        $params['transaction_id'] = $this->getTransactionIdFromNoFraud($apiUrl, $orderId, $apiToken);

        if(!$params['transaction_id']){
            return false;
        }

        return $this->scrubEmptyValues($params);
    }

    protected function getTransactionStatusUrl($apiUrl, $orderId, $apiToken){
        return $apiUrl . self::TRANSACTION_STATUS_ENDPOINT . DIRECTORY_SEPARATOR . $apiToken . DIRECTORY_SEPARATOR . $orderId;
    }

    protected function getTransactionIdFromNoFraud( $apiUrl, $orderId, $apiToken){
        $params = [];
        $response = $this->send($params, $this->getTransactionStatusUrl($apiUrl, $orderId, $apiToken), 'GET');

        if ( isset($response['http']['response']['body']['id']) ){
            return $response['http']['response']['body']['id'];
        }
        return false;
    }

}
