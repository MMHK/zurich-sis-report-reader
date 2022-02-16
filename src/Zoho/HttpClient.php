<?php


namespace MMHK\Zoho;


use GuzzleHttp\Client;

trait HttpClient
{
    private $httpClient;

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
        $this->httpClient = new Client($this->defaultParams);
    }

    public function handleResponse(\Psr\Http\Message\ResponseInterface $resp) {
        $content = $resp->getBody()->getContents();

        $json = json_decode($content, true);

        if (array_key_exists('error', $json)) {
            throw new \Exception($json['error']);
        }

        return $json;
    }
}