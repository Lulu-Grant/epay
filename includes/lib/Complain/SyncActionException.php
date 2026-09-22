<?php
namespace lib\Complain;

class SyncActionException extends \RuntimeException
{
    private $persistedCode;

    public function __construct($persistedCode, \Throwable $previous)
    {
        parent::__construct('投诉已保存，但后续自动处理失败', 0, $previous);
        $this->persistedCode = $persistedCode;
    }

    public function persistedCode()
    {
        return $this->persistedCode;
    }
}
