<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Enum;

class Account
{
    public function __construct(public AccountStatus $status)
    {

    }
}
