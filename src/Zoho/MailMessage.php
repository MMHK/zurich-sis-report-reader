<?php


namespace MMHK\Zoho;


use ArrayObject;

class MailMessage extends ArrayObject
{
    public function __construct($array = [])
    {
        parent::__construct($array, 0);
    }

    public function getMessageID() {
        return $this->offsetGet('messageId');
    }

    public function getFolderId() {
        return $this->offsetGet('folderId');
    }

    public function hasAttachment() {
        return !!($this->offsetGet('hasAttachment'));
    }

    public function getSubject() {
        return $this->offsetGet('subject');
    }

    public function getContent() {
        return $this->offsetGet('content');
    }

    public function getContentText() {
        $content = $this->getContent();
        $content = str_replace(['<br>', '<br />', '<br/>'], ' ', $content);
        return str_replace(["\n", "\r"], ' ', strip_tags($content));
    }

    public function getAttachments() {
        return $this->offsetGet('attachments') ?: [];
    }

    public function getAccountId() {
        return $this->offsetGet('accountId');
    }

    public function getHash() {
        return md5(str_replace([' (password) ', ' '], '', $this->getSubject()));
    }

    public function getPwd() {
        preg_match('/password\:\ ([^\ ]+)/i', $this->getContentText(), $matches);
        if (count($matches) > 1) {
            return array_get($matches, '1');
        }

        return null;
    }
}