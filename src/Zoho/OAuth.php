<?php


namespace MMHK\Zoho;


class OAuth
{
    const SCOPE_EMAIL_READ = 'ZohoMail.messages.READ';
    const SCOPE_ACCOUNT_READ = 'ZohoMail.accounts.READ';
    const SCOPE_FOLDER_READ = 'ZohoMail.folders.READ';
    const ENDPOINT_CODE = 'https://accounts.zoho.com/oauth/v3/device/code';
    const ENDPOINT_TOKEN = 'https://accounts.zoho.com/oauth/v3/device/token';

    use HttpClient;

    public function requireCode() {
        $params = [
            'form_params' => [
                'client_id' => $this->clientId,
                'grant_type' => 'device_request',
                'scope' => implode(',', [self::SCOPE_EMAIL_READ, self::SCOPE_ACCOUNT_READ]),
                'access_type' => 'offline',
            ],
        ];

        $resp = $this->request('POST', self::ENDPOINT_CODE, $params);

        return $this->handleResponse($resp);
    }

    /**
     * @param $deviceCode
     * @return Token
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function pullToken($deviceCode) {
        $params = [
            'form_params' => [
                'client_id' => $this->clientId,
                'grant_type' => 'device_token',
                'client_secret' => $this->clientSecret,
                'code' => $deviceCode,
            ],
        ];

        $resp = $this->request('POST', self::ENDPOINT_TOKEN, $params);

        $resp = $this->handleResponse($resp);

        return new Token($this->clientId, $this->clientSecret, $resp);
    }
}
