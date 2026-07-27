<?php

namespace App\Enums;

enum CacheKey:string
{
    case HOME = 'home';

    case TRENDING = 'trending';

    case LATEST = 'latest';

    case DRAMA = 'drama';

    case SEARCH = 'search';

    case SETTINGS = 'settings';

    case BANNER = 'banner';

    case GENRES = 'genres';

    case COUNTRIES = 'countries';
}