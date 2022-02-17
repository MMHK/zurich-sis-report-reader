<?php


namespace MMHK;


use GuzzleHttp\Client;
use MMHK\common\withCache;
use MMHK\Zoho\HttpClient;
use MMHK\Zoho\MailAttachment;
use MMHK\Zoho\MailMessage;
use MMHK\Zoho\OAuth;

class ZohoMailHelper extends GmailHelper
{
    const CACHE_KEY_TOKEN = 'zoho_token';
    const CACHE_KEY_DEVICE_CODE = 'device_code';

    use HttpClient;
    use withCache;

    /**
     * @var \MMHK\Zoho\Token|null
     */
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
        $mimeType = $resp->getHeaderLine('Content-Type');
        $httpStatusCode = $resp->getStatusCode();
        $content = $resp->getBody()->getContents();

        if (stripos($mimeType, 'json') !== false) {
            $json = json_decode($content, true);

            $stateCode = array_get($json, 'status.code', 0);
            if ($stateCode != 200) {
                throw new \Exception($content);
            }

            if (array_key_exists('error', $json)) {
                throw new \Exception($json['error']);
            }

            return array_get($json, 'data', []);
        }

        if ($httpStatusCode != 200) {
            throw new \Exception($content, $httpStatusCode);
        }

        return $content;
    }


    public function call($method, $uri, $params = []) {
        $params = array_merge([
            'headers' => $this->token->getAuthHeaders(),
        ], $params);

        $resp = $this->request($method, sprintf('https://mail.zoho.com/api/%s', $uri), $params);

        return $this->handleResponse($resp);
    }

    public function getAccountID($emailAddress = null) {
        $accountList = $this->call('GET', 'accounts');
        if (!empty($emailAddress)) {
            $account = array_first($accountList, function($index, $row) use ($emailAddress) {
                return array_get($row, 'mailboxAddress') == $emailAddress;
            });
        } else {
            $account = array_get($accountList, '0');
        }

        if (!empty($account)) {
            return $account['accountId'];
        }
        return null;
    }

    public function getMailList($accountId, $limit = 500, $query = null) {
        $q = $query ?: 'sender:zurich.fwd@mixmedia.com::subject:SIS API Upload';

        $list = $this->call('GET', sprintf('accounts/%d/messages/search', $accountId), [
           'query' => [
               'searchKey' => $q,
               'limit' => $limit,
           ],
        ]);

        return array_map(function($row) use ($accountId) {
            $data = array_merge($row, compact('accountId'));
            $msg = new MailMessage($data);

            return $msg;
        }, $list);
    }

    public function extendMessage(MailMessage $message) {
        $message = $this->fillContent($message);
        $message = $this->fillAttachments($message);

        return $message;
    }
    /**
     * @param MailMessage $message
     * @return MailMessage
     */
    public function fillContent(MailMessage $message) {
        $result = $this->call('GET', sprintf('accounts/%d/folders/%d/messages/%d/content'
            , $message->getAccountId(), $message->getFolderId(), $message->getMessageID()));

        $message->offsetSet('content', $result['content']);

        return $message;
    }

    public function fillAttachments(MailMessage $message) {
        if (!$message->hasAttachment()) {
            return $message;
        }

        $attachmentinfo = $this->call('GET', sprintf('accounts/%d/folders/%d/messages/%d/attachmentinfo'
            , $message->getAccountId(), $message->getFolderId(), $message->getMessageID()));
        $attachmentList = array_get($attachmentinfo, 'attachments', []);

        $attachmentList = array_map(function($row) use ($message) {
            $attachmentId = array_get($row, 'attachmentId');
            $content = $this->call('GET', sprintf('accounts/%d/folders/%d/messages/%d/attachments/%d'
                , $message->getAccountId(), $message->getFolderId(), $message->getMessageID(), $attachmentId));
            $data = array_merge($row, compact('content'));
            return new MailAttachment($data);
        }, $attachmentList);

        $message->offsetSet('attachments', $attachmentList);

        return $message;
    }

    public function saveAttachment(MailMessage $message) {
        $attachmentList = $message->getAttachments();
        foreach ($attachmentList as $attachment) {
            /**
             * @var $attachment \MMHK\Zoho\MailAttachment
             */
            $save_file_name = $this->temp_path . '/' . $message->getHash() . '_' . $attachment->getAttachmentName();
            if (file_exists($save_file_name)) {
                continue;
            }
            file_put_contents($save_file_name, $attachment->getContent());
        }
    }

    public function run($limit = 500, $query = null) {
        $accountID = $this->getAccountID();
        $list = $this->getMailList($accountID, $limit, $query);

        $total = count($list);
        echo sprintf("total get record:[%d]\n", $total);
        $index = 1;
        $list = array_map(function ($row) use ($total, &$index) {
            /**
             * @var $row \MMHK\Zoho\MailMessage
             */
            $cached = $this->getCache($row->getMessageID());
            if (empty($cached)) {
                $cached = $this->extendMessage($row);
                $cached = $this->setCache($row->getMessageID(), $cached);
            }
            echo sprintf("begin get detail:[%d/%d]\n", $index, $total);
            $index++;
            return $cached;
        }, $list);

        $has_attachment_list = array_values(array_filter($list, function ($row){
            /**
             * @var $row \MMHK\Zoho\MailMessage
             */
            return $row->hasAttachment();
        }));

        echo sprintf("total get attachment record:[%d]\n", count($has_attachment_list));

        $pwd_list = array_values(array_filter($list, function ($row){
            /**
             * @var $row \MMHK\Zoho\MailMessage
             */
            return !empty($row->getPwd());
        }));

        echo sprintf("total get password record:[%d]\n", count($pwd_list));

        $attachment_count = count($has_attachment_list);
        foreach ($has_attachment_list as $i => $row) {
            /**
             * @var $row \MMHK\Zoho\MailMessage
             */
            $this->saveAttachment($row);

            echo sprintf("downloaded attachment [%d/%d].\n", ($i+1),  $attachment_count);
        }

        $pwd_count = count($pwd_list);
        foreach ($pwd_list as $j => $row) {
            /**
             * @var $row \MMHK\Zoho\MailMessage
             */
            $this->unzip($row->getHash(), $row->getPwd());

            echo sprintf("unzip attachment [%d/%d].\n", ($j+1),  $pwd_count);
        }
    }
}