<?php

namespace App\Enums;

enum UsdtReconciliationVerdict: string
{
    case Consistent = 'consistent';
    case Findings = 'findings';
}
