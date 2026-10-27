<?php

declare(strict_types=1);

namespace Pam\Native\Analytics;

enum AnalyticsOperationState: int
{
    case Succeeded = 1;
    case Failed = 2;
    /** No Firebase configuration in the build: the call was a silent no-op. */
    case Unavailable = 3;
}
