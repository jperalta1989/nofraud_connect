<?php

namespace NoFraud\Connect\Api\Request\Handler;

class AbstractHandler
{
    /**
     * @var \NoFraud\Connect\Logger\Logger
     */
    protected $logger;

    /**
     * AbstractHandler constructor.
     * @param \NoFraud\Connect\Logger\Logger $logger
     */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * @param array  $params | NoFraud request object parameters
     * @param string $apiUrl | The URL to send to
     * @param string $requestType | Request Type
     */
    public function send( $params, $apiUrl, $requestType = 'POST')
    {
        $ch = curl_init();

        if (!strcasecmp($requestType,'post')) {
            $body = json_encode($params);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($body)));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_URL, $apiUrl );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if(curl_errno($ch)){
            $this->logger->logApiError($apiUrl, curl_error($ch),$responseCode);
        }

        $response = [
            'http' => [
                'response' => [
                    'body' => json_decode($result, true),
                    'code' => $responseCode,
                    'time' => curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME),
                ],
                'client' => [
                    'error' => curl_error($ch),
                ],
            ],
        ];

        curl_close($ch);

        return $this->scrubEmptyValues($response);
    }

    protected function scrubEmptyValues($array)
    {
        // Removes any empty values (except for 'empty' numerical values such as 0 or 00.00)
        foreach ($array as $key => $value) {

            if (is_array($value)) {
                $value = $this->scrubEmptyValues($value);
                $array[$key] = $value;
            }

            if ( empty($value) && !is_numeric($value) ) {
                unset($array[$key]);
            }

        }

        return $array;
    }
}
