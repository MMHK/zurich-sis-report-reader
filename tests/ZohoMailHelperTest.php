<?php


namespace Tests;


use MMHK\common\withCache;
use MMHK\ZohoMailHelper;
use PHPUnit\Framework\TestCase;

class ZohoMailHelperTest extends TestCase
{
    const CACHE_KEY_EMAILLIST = 'emaillist';

    use withCache;

    protected $service;

    protected function setUp()
    {
        parent::setUp();

        $this->service = new ZohoMailHelper(dirname(__DIR__).'/temp', __DIR__.'/secret');
    }

    public function test_getAccount() {
//        $accountID = $this->service->getAccountID('info@360studio.hk');
        $accountID = $this->service->getAccountID();

        dump($accountID);

        $this->assertNotEmpty($accountID);
    }

    public function test_getMailList() {
        $list = $this->service->getMailList('5896371000000008002');

        dump($list);

        $this->setCache(self::CACHE_KEY_EMAILLIST, $list);

        $this->assertNotEmpty($list);
    }

    public function test_extendMessage() {
        $list = $this->getCache(self::CACHE_KEY_EMAILLIST, []);

        foreach ($list as & $msg) {
            /**
             * @var $msg \MMHK\Zoho\MailMessage
             */

            $msg = $this->service->extendMessage($msg);
            $this->setCache($msg->getMessageID(), $msg);
            sleep(1);

            dump($msg);
        }

        $this->assertNotEmpty($list);
    }

    public function test_run() {
        $this->service->run();
    }
}