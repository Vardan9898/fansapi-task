<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked'; // upstream rejected its credentials; no new fetches go through it
}
