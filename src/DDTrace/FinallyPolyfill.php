<?php

namespace DDTrace;

final class FinallyPolyfill
{
    /** @var \Closure */
    private $onScopeLeave;

    public function __construct(\Closure $onScopeLeave)
    {
        $this->onScopeLeave = $onScopeLeave;
    }

    public function __destruct()
    {
        call_user_func($this->onScopeLeave);
    }
}
