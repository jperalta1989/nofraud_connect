<?php
 
namespace NoFraud\Connect\Api;
 
class ResponseHandler
{
    public function __construct(
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->logger = $logger;
    }

    public function buildComment( $response )
    {
        if ( isset($response['body']['decision']) ){
            return $this->commentFromDecision( $response['body'] );
        }

        if ( isset($response['body']['Errors']) ){
            $comment = "NoFraud was unable to assess this transaction due to an error.";
            return $comment;
        }

        switch ( $response['http_code'] ){

            case 200:
                $comment  = "The payment processor provided too little information for NoFraud to assess this transaction.";
                $comment .= "<br>Not to worry: this is the expected behavior for some payment processors.";
                return $comment;

            case 403:
                $comment = "Failed to authenticate with NoFraud. Please ensure that you have correctly entered your API Token under 'Stores > Configuration > NoFraud > Connect'." ;
                return $comment;

            default:
                $comment = "We're sorry. It appears the NoFraud service was unavailable at the time of this transaction.";
                return $comment;

        }

    }

    protected function commentFromDecision( $responseBody )
    {
        $id       = $responseBody['id'];
        $decision = $responseBody['decision'];

        $comment = "NoFraud decision: " . strtoupper($decision) . "</br>";
        
        if ($decision == "review") {
            $comment .= "(We're already looking into it on your behalf.)";
        }

        $comment .= "<br>You may view the report " . $this->linkToReport( $id, 'here' ) . '.' ;

        return $comment;
    }

    protected function linkToReport( $transactionId, $linkText )
    {
        return '<a target="_blank" href="https://portal.nofraud.com/transaction/' . $transactionId . ">{$linkText}</a>" ;
    }

}
