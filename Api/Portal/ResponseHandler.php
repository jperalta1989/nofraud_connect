<?php
 
namespace NoFraud\Connect\Api\Portal;
 
class ResponseHandler
{
    protected $logger;

    public function __construct(
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->logger = $logger;
    }

    public function buildComment( $resultMap , $commentType = NULL)
    {
        if ( isset($resultMap['http']['client']['error']) ){
            $comment = "Failed to connect with the NoFraud service due to an error.";
            return $comment;
        }

        if ( isset($resultMap['http']['response']['body']) ){

            $responseBody = $resultMap['http']['response']['body'];

            if ( isset($responseBody['Errors']) ){
                return $this->commentFromNoFraudErrors( $responseBody['Errors'] );
            }
            else if($commentType == 'cancel'){
                return $this->commentFromNoFraudCancellation($responseBody);
            }
            else {
                return $this->commentFromNoFraudDecision( $responseBody );
            }
        }

        if ( isset($resultMap['http']['response']['code']) ){
            return $this->commentFromResponseCode( $resultMap['http']['response']['code'] );
        }
    }

    protected function commentFromNoFraudCancellation( $responseBody ){
        if ($responseBody['code'] == 403){
            return false;
        }

        return 'NoFraud Transaction Cancel: ' . $responseBody['message'];
    }

    protected function commentFromNoFraudDecision( $responseBody )
    {
        $id       = $responseBody['id'];
        $decision = $responseBody['decision'];

        $comment = "NoFraud decision: " . strtoupper($decision) . ' ( ' . $this->noFraudLink( $id, 'view report' ) . ' )' ;
        
        if ($decision == "review") {
            $comment .= "<br>This transaction is being looked into on your behalf.";
        }

        return $comment;
    }

    protected function commentFromNoFraudErrors( $errors )
    {
        $error_s = count($errors) > 1 ? 'errors' : 'error' ;

        $comment = "NoFraud was unable to assess this transaction due to the following {$error_s}:";
        foreach ( $errors as $error ){
            $comment .= "<br>\"{$error}\"" ;
        }

        return $comment;
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

        return $comment;
    }

}
