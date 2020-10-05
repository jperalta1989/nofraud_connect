<?php
 
namespace NoFraud\Connect\Api;
 
class ResponseHandler
{
    protected $logger;

    public function __construct(
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->logger = $logger;
    }

    public function getTransactionData( $resultMap )
    {
        if ( isset($resultMap['http']['client']['error']) ){
            return $this->_prepareErrorData("Failed to connect with the NoFraud service due to an error.");
        }

        if ( isset($resultMap['http']['response']['body']) ){

            $responseBody = $resultMap['http']['response']['body'];

            if ( isset($responseBody['Errors']) ){
                return $this->commentFromNoFraudErrors( $responseBody['Errors'] );
            } else {
                return $this->commentFromNoFraudDecision( $responseBody );
            }
        }

        if ( isset($resultMap['http']['response']['code']) ){
            return $this->commentFromResponseCode( $resultMap['http']['response']['code'] );
        }
    }

    protected function commentFromNoFraudDecision( $responseBody )
    {
        $id       = $responseBody['id'];
        $decision = $responseBody['decision'];

        $comment = "NoFraud decision: " . strtoupper($decision) . ' ( ' . $this->noFraudLink( $id, 'view report' ) . ' )' ;
        
        if ($decision == "review") {
            $comment .= "<br>This transaction is being looked into on your behalf.";
        }

        return $this->_prepareData($comment, $decision, $id);
    }

    protected function commentFromNoFraudErrors( $errors )
    {
        $error_s = count($errors) > 1 ? 'errors' : 'error' ;

        $comment = "NoFraud was unable to assess this transaction due to the following {$error_s}:";
        foreach ( $errors as $error ){
            $comment .= "<br>\"{$error}\"" ;
        }

        return $this->_prepareErrorData($comment);
    }

    protected function noFraudLink( $transactionId, $linkText )
    {
        return "<a target=\"_blank\" href=\"https://portal.nofraud.com/transaction/{$transactionId}\">{$linkText}</a>" ;
    }

    protected function commentFromResponseCode( $responseCode )
    {
        switch ( $responseCode ){
            case 403:
                $comment = "Failed to authenticate with NoFraud. Please ensure that you have correctly entered your API Token under 'Stores > Configuration > NoFraud > Connect'.";
                break;

            default:
                $comment = "We're sorry. It appears the NoFraud service was unavailable at the time of this transaction.";
                break;
        }

        return $this->_prepareErrorData($comment);
    }

    private function _prepareErrorData($comment){
        return $this->_prepareData($comment, 'Error', null);
    }

    private function _prepareData($comment, $status, $id){
        return array(
            'comment' => $comment,
            'status' => $status,
            'id' => $id,
        );
    }

}
