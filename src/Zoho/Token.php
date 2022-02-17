<?php


namespace MMHK\Zoho;


use Carbon\Carbon;
use GuzzleHttp\Client;

class Token implements \Serializable
{
    const ENDPOINT_REFRESH_TOKEN = 'https://accounts.zoho.com/oauth/v2/token';

    use HttpClient;

    protected $raw = [];
    /**
     * @var
     */
    protected $expired_at;

    public function __construct($clientId, $clientSecret, $data)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->raw = $data;
        $this->expired_at = Carbon::now()->addSeconds($this->getExpiresIn());
    }

    /**
     * @return string
     */
    public function getAccessToken() {
        if ($this->isExpired()) {
            $this->refreshToken();
        }
        return array_get($this->raw, 'access_token');
    }

    /**
     * @return string
     */
    public function getRefreshToken() {
        return array_get($this->raw, 'refresh_token');
    }

    /**
     * @return string
     */
    public function getApiDomain() {
        return array_get($this->raw, 'api_domain');
    }

    /**
     * @return string
     */
    public function getTokenType() {
        return array_get($this->raw, 'token_type');
    }

    /**
     * @return number
     */
    public function getExpiresIn() {
        return array_get($this->raw, 'expires_in', 0);
    }

    public function isExpired() {
        return $this->expired_at->isbefore(Carbon::now());
    }

    /**
     * @return string[]
     */
    public function getAuthHeaders() {
        return [
            'Authorization' => "{$this->getTokenType()} {$this->getAccessToken()}",
        ];
    }

    public function refreshToken() {
        $params = [
            'form_params' => [
                'refresh_token' => $this->getRefreshToken(),
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token',
            ],
        ];

        $resp = $this->request('POST', self::ENDPOINT_REFRESH_TOKEN, $params);
        $token = $this->handleResponse($resp);

        $this->raw = array_merge($this->raw, $token);
    }

    public function serialize()
    {
        $base = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'expired_at' => $this->expired_at->timestamp,
        ];
        return json_encode(array_merge($base, $this->raw));
    }

    public function unserialize($serialized)
    {
        $base = json_decode($serialized, true);
        $this->clientId = array_get($base, 'client_id');
        $this->clientSecret = array_get($base, 'client_secret');
        $this->expired_at = Carbon::createFromTimestamp(array_get($base, 'expired_at'), 0);

        $base = array_except($base, ['client_id', 'client_secret', 'expired_at']);
        $this->raw = $base;
    }
}