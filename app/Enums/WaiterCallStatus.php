<?php

namespace App\Enums;

enum WaiterCallStatus: string
{
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
}
