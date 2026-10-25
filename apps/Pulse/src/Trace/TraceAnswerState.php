<?php

declare(strict_types=1);

namespace App\Trace;

enum TraceAnswerState: string
{
    case Answered = 'answered';
    case Waiting = 'waiting';
    case NotAnswering = 'not_answering';
    case Refused = 'refused';
}
