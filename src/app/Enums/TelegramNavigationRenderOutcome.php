<?php

namespace App\Enums;

enum TelegramNavigationRenderOutcome
{
    case Applied;
    case Unchanged;
    case Superseded;
    case Replaced;
    case Ambiguous;

    /**
     * The callback did not target the current canonical root. It is only
     * produced by the presentation seam, never by a direct root render.
     */
    case Stale;
}
