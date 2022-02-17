<?php


namespace MMHK\Zoho;

use ArrayObject;

class MailAttachment extends ArrayObject
{
    public function __construct($array = [])
    {
        parent::__construct($array, 0);
    }


    public function getAttachmentName() {
        return $this->offsetGet('attachmentName');
    }

    public function getFileType() {
        return $this->offsetGet('fileType');
    }

    public function getAttachmentId() {
        return $this->offsetGet('attachmentId');
    }

    public function getContent() {
        return $this->offsetGet('content');
    }
}