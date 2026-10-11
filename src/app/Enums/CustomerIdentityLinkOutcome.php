<?php

namespace App\Enums;

enum CustomerIdentityLinkOutcome: string
{
    case Linked = 'linked';
    case AlreadyLinked = 'already_linked';
    case SubjectOwnedByAnotherCustomer = 'subject_owned_by_another_customer';
    case ProviderAlreadyLinked = 'provider_already_linked';
    case TelegramOwnershipConflict = 'telegram_ownership_conflict';
}
