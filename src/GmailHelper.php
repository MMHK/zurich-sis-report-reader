<?php
/**
 * Created by PhpStorm.
 * User: mixmedia
 * Date: 2019/1/18
 * Time: 17:18
 */

namespace MMHK;


class GmailHelper
{
    const CREDENTIALS_JSON_FILE = '/credentials.json';
    const TOKEN_FILE = '/token.json';
    const USER_ME = 'me';

    protected $cache_path;

    protected $temp_path = '/temp';

    /**
     * @var \Google_Service_Gmail
     */
    protected $service;

    /**
     * GmailHelper constructor.
     * @param $cache_path
     */
    public function __construct($cache_path, $temp_path = '/temp')
    {
        $this->cache_path = $cache_path;

        $this->service = $this->getGmailService();
        $this->temp_path = $temp_path;
        if (!file_exists($this->temp_path)) {
            mkdir($this->temp_path, 0777, true);
        }
    }

    public function getClient()
    {
        $client = new \Google_Client();
        $client->setApplicationName('SIS Report Reader');
        $client->setScopes([
//            \Google_Service_Gmail::MAIL_GOOGLE_COM,
            \Google_Service_Gmail::GMAIL_READONLY,
            \Google_Service_Gmail::GMAIL_LABELS,
//            \Google_Service_Gmail::GMAIL_METADATA,
            \Google_Service_Gmail::GMAIL_MODIFY,
        ]);
        $client->setAuthConfig($this->cache_path.self::CREDENTIALS_JSON_FILE);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');

        // Load previously authorized token from a file, if it exists.
        // The file token.json stores the user's access and refresh tokens, and is
        // created automatically when the authorization flow completes for the first
        // time.
        $tokenPath = $this->temp_path . self::TOKEN_FILE;
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
        }

        // If there is no previous token or it's expired.
        if ($client->isAccessTokenExpired()) {
            // Refresh the token if possible, else fetch a new one.
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                // Request authorization from the user.
                $authUrl = $client->createAuthUrl();
                printf("Open the following link in your browser:\n%s\n", $authUrl);
                print 'Enter verification code: ';
                $authCode = trim(fgets(STDIN));

                // Exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);

                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new \Exception(join(', ', $accessToken));
                }
            }
            // Save the token to a file.
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
        return $client;
    }

    public function getGmailService() {
        return new \Google_Service_Gmail($this->getClient());
    }

    public function getAttachment($msg_id, $attachment_id) {
        return $this->getGmailService()
            ->users_messages_attachments
            ->get(self::USER_ME, $msg_id, $attachment_id)
            ->getData();
    }

    public function getMailDeteil($msg_id) {
        $cache_file = $this->temp_path . '/' . $msg_id . '.json';
        if (file_exists($cache_file)) {
            return json_decode(file_get_contents($cache_file), 1);
        }

        try {
            $mail = $this->getGmailService()->users_messages->get(self::USER_ME, $msg_id);
        } catch (\Exception $e) {
            print_r($e->getMessage());
            return [];
        }

        $subject = '';
        $attachment_id = 0;
        $file_hash = '';
        $pwd = '';
        $filename = '';

        $headers = $mail->getPayload()->getHeaders();

        array_walk($headers, function ($header) use (&$subject, &$file_hash) {
            /**
             * @var $header \Google_Service_Gmail_MessagePart
             */
            $header_name = $header->getName();
            if ($header_name == 'Subject') {
                $subject = $header->getValue();
                $file_hash = md5(str_replace(' (password)', '', $subject));
            }
        });

        $content = $mail->getSnippet();
        preg_match('/password\:\ ([^\ ]+)/i', $content, $matches);
        if (count($matches) > 1) {
            $pwd = array_get($matches, '1');
        }

        $parts = $mail->getPayload()->getParts();
        $part = array_get($parts, '1');
        /*
         * @var $part \Google_Service_Gmail_MessagePart
         */
        if ($part) {
            $attachment_id = $part->getBody()->getAttachmentId();
            $filename = $part->getFilename();
        }

        $data = [
            'msg_id' => $msg_id,
            'subject' => $subject,
            'content' => $content,
            'password' => $pwd,
            'attachment_id' => $attachment_id,
            'filename' => $filename,
            'attachment_hash' => $file_hash,
        ];

        file_put_contents($cache_file, json_encode($data));

        return $data;
    }

    public function downloadAttachment($hash, $filename, $msg_id, $attachment_id) {
        $rule = $this->temp_path . '/' . $hash . '*.zip';
        $file = array_get(glob($rule), '0');
        if ($file) {
            return;
        }

        $save_file_name = $this->temp_path . '/' . $hash . '_' . $filename;
        $data = $this->getAttachment($msg_id, $attachment_id);
        if ($data) {
            $data = strtr($data, array('-' => '+', '_' => '/'));
            file_put_contents($save_file_name, base64_decode($data));
        }
    }

    public function unzip($hash, $pwd) {
        $rule = $this->temp_path . '/' . $hash . '*.zip';
        $file = array_get(glob($rule), '0');
        if (!$file) {
            return;
        }
        $csv = str_replace($hash.'_', '', $file);
        if (file_exists($csv)) {
            return;
        }
        $zipFile = new \PhpZip\ZipFile();
        $zipFile
            ->openFile($file) // open archive from file
            ->setReadPassword($pwd) // set password for all entries
            ->extractTo(dirname($file)); // extract files to the specified directory
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
            return $this->getMailDeteil($row->getId());
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

    public function clearTempFiles() {
        $list = glob($this->temp_path. '/*.*');
        foreach ($list as $row) {
            unlink($row);
        }
    }

    public function export_error_report($save_dir) {
        $rule = $this->temp_path . '/*_SISAPI_UL_INTR_RPT_*.csv';
        $list = glob($rule);
        if (!file_exists($save_dir)) {
            mkdir($save_dir, 0777, true);
        }
        foreach ($list as $row) {
            $dist = $save_dir . '/' . basename($row);
            copy($row, $dist);
        }
    }
}