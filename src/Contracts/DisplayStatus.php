<?php

namespace Sifrious\Molly\Contracts;

enum DisplayStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Failed = 'failed';
    case Stopped = 'stopped';
    case HandedOff = 'handed_off';
    case Merged = 'merged';
}
