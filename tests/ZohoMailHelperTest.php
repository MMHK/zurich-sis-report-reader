<?php


namespace Tests;


use MMHK\ZohoMailHelper;
use PHPUnit\Framework\TestCase;

class ZohoMailHelperTest extends TestCase
{
    protected $service;

    protected function setUp()
    {
        parent::setUp();

        $this->service = new ZohoMailHelper(dirname(__DIR__).'/temp', __DIR__.'/secret');
    }

    public function test_getAccount() {
        $accountID = $this->service->getAccountID('info@360studio.hk');

        dump($accountID);

        $this->assertNotEmpty($accountID);
    }

    public function test_getMailList() {
        $list = $this->service->getMailList('5896371000000008002');

        dump($list);

        $this->assertNotEmpty($list);
    }
}