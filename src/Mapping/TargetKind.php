<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Mapping;

enum TargetKind: string
{
    case Post = 'post';
    case Term = 'term';
    case User = 'user';
    case Options = 'options';
}
