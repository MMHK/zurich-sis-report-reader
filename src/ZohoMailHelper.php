<?php


namespace MMHK;


use GuzzleHttp\Client;
use MMHK\common\withCache;
use MMHK\Zoho\HttpClient;
use MMHK\Zoho\OAuth;

class ZohoMailHelper extends GmailHelper
{
    const CACHE_KEY_TOKEN = 'zoho_token';
    const CACHE_KEY_DEVICE_CODE = 'device_code';

    use HttpClient;
    use withCache;

    protected $token;

    /**
     * GmailHelper constructor.
     * @param $cache_path
     */
    public function __construct($cache_path, $temp_path = '/temp')
    {
        $this->cacheDir = $cache_path;
        $this->temp_path = $temp_path;
        if (!file_exists($this->temp_path)) {
            mkdir($this->temp_path, 0777, true);
        }
        $this->httpClient = new Client($this->defaultParams);
        $this->initToken();
    }

    /**
     * @return array
     */
    protected function loadConfig() {
        $json = file_get_contents($this->temp_path.'/zoho.json');
        $options = json_decode($json, true);
        if (!empty($options)) {
            return $options;
        }

        return [];
    }

    protected function initToken() {
        $this->token = $this->getCache(self::CACHE_KEY_TOKEN);
        if (empty($this->token)) {
            $options = $this->loadConfig();
            $auth = new OAuth($options['client_id'], $options['client_secret']);

            $resp = $auth->requireCode();
            if (!empty($resp)) {
                $this->setCache(self::CACHE_KEY_DEVICE_CODE, array_get($resp, 'device_code'));

                printf("Open the following link in your browser:\n%s\nand enter code:%s",
                    array_get($resp, 'verification_url'),
                    array_get($resp, 'user_code'));
            }

            pullToken:
            printf('try to get token');
            $deviceCode = $this->getCache(self::CACHE_KEY_DEVICE_CODE);
            $this->token = $auth->pullToken($deviceCode);
            $this->setCache(self::CACHE_KEY_TOKEN, $this->token);

            if (empty($this->token)) {
                sleep(3);
                goto pullToken;
            }
        }
    }

    public function handleResponse(\Psr\Http\Message\ResponseInterface $resp)
    {
        $content = $resp->getBody()->getContents();

        $json = json_decode($content, true);

        $stateCode = array_get($json, 'status.code', 0);
        if ($stateCode != 200) {
            throw new \Exception($json['description']);
        }

        if (array_key_exists('error', $json)) {
            throw new \Exception($json['error']);
        }

        return array_get($json, 'data', []);
    }


    public function call($method, $uri, $params = []) {
        $params = array_merge([
            'headers' => $this->token->getAuthHeaders(),
        ], $params);
        $resp = $this->httpClient->request($method, sprintf('http://mail.zoho.com/api/%s', $uri), $params);

        return $this->handleResponse($resp);
    }

    public function getAccountID($emailAddress) {
        $accountList = $this->call('GET', 'accounts');
        $account = array_first($accountList, function($index, $row) use ($emailAddress) {
            return array_get($row, 'mailboxAddress') == $emailAddress;
        });
        if (!empty($account)) {
            return $account['accountId'];
        }
        return null;
    }

    public function getMailList($accountId, $limit = 500, $query = null) {
        $q = $query ?: 'sender:zurich.fwd@mixmedia.com::subject:SIS API Upload';

        return $this->call('GET', sprintf('accounts/%d/messages/search', $accountId), [
           'query' => [
               'searchKey' => $q,
               'limit' => $limit,
           ],
        ]);
    }

    public function getMailDetail($messageID) {

    }


    public function run($limit = 500, $query = null) {
        $service = $this->getGmailService();

        $page_token = 0;
        $list = [];
        $q = $query ?: 'from:(IT-GI@hk.zurich.com) subject:(SIS API Upload to SIS Report for motors.com.hk)';
        while ($page_token !== null) {
            $resp = $service->users_messages->listUsersMessages('me', [
                'includeSpamTrash' => true,
                'maxResults' => $limit,
                'pageToken' => $page_token,
                'q' => $q
            ]);
            $new_list = $resp->getMessages();
            if (!empty($new_list)) {
                $list = array_merge($list, $new_list);
            } else {
                break;
            }
            $page_token = $resp->getNextPageToken();
        }

        $total = count($list);
        echo sprintf("total get record:[%d]\n", $total);
        $index = 1;
        $list = array_map(function ($row) use ($total, &$index) {
            /**
             * @var $row \Google_Service_Gmail_Message
             */
            echo sprintf("begin get detail:[%d/%d]\n", $index, $total);
            $index++;
            return $this->getMailDetail($row->getId());
        }, $list);

        $has_attachment_list = array_values(array_filter($list, function ($row){
            return !empty($row['attachment_id']);
        }));

        echo sprintf("total get attachment record:[%d]\n", count($has_attachment_list));

        $pwd_list = array_values(array_filter($list, function ($row){
            return !empty($row['password']);
        }));

        echo sprintf("total get password record:[%d]\n", count($pwd_list));

        $attachment_count = count($has_attachment_list);
        foreach ($has_attachment_list as $i => $row) {
            $this->downloadAttachment($row['attachment_hash'], $row['filename'],
                $row['msg_id'], $row['attachment_id']);

            echo sprintf("downloaded attachment [%d/%d].\n", $i,  $attachment_count);
        }

        $pwd_count = count($pwd_list);
        foreach ($pwd_list as $j => $row) {
            $this->unzip($row['attachment_hash'], $row['password']);

            echo sprintf("unzip attachment [%d/%d].\n", $j,  $pwd_count);
        }
    }
}