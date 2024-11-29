<?php

namespace CommonKnowledge\JoinBlock\Exceptions;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class JoinBlockException extends \Exception
{
    public $fields;

    public function __construct($message, $code, $fields = [])
    {
        $this->fields = $fields;
        parent::__construct($message, $code);
    }

    public function getFields()
    {
        return $this->fields;
    }
}
