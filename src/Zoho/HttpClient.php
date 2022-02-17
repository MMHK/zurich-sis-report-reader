<?php


namespace MMHK\Zoho;


use GuzzleHttp\Client;

trait HttpClient
{
    protected $clientId;
    protected $clientSecret;

    protected $defaultParams = [
        'timeout' => 60,
    ];
    /**
     * static constructor.
     */
    public function __construct($clientId, $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    public function handleResponse(\Psr\Http\Message\ResponseInterface $resp) {
        $mimeType = $resp->getHeaderLine('Content-Type');
        $httpStatusCode = $resp->getStatusCode();
        $content = $resp->getBody()->getContents();

        if (stripos($mimeType, 'json') !== false) {
            $json = json_decode($content, true);

            if (array_key_exists('error', $json)) {
                throw new \Exception($content);
            }

            return $json;
        }

        if ($httpStatusCode != 200) {
            throw new \Exception($content, $httpStatusCode);
        }

        return $content;
    }

    public function request($method, $url, $params = []) {
        $httpClient = new Client($this->defaultParams);
        return $httpClient->request($method, $url, $params);
    }
}