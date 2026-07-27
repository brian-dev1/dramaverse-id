<?php

namespace App\Enums;

enum EpisodeStatus:string
{
    case DRAFT = 'draft';

    case SCHEDULED = 'scheduled';

    case PUBLISHED = 'published';

    case ARCHIVED = 'archived';
}