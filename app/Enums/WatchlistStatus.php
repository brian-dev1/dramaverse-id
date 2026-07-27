<?php

namespace App\Enums;

enum WatchlistStatus:string
{
    case WATCHING='watching';

    case COMPLETED='completed';

    case PLAN_TO_WATCH='plan_to_watch';

    case ON_HOLD='on_hold';

    case DROPPED='dropped';
}