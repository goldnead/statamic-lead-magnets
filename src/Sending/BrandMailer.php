<?php

namespace Goldnead\LeadMagnets\Sending;

use Goldnead\BrandContext\Sending\BrandMailer as BrandContextMailer;
use Goldnead\LeadMagnets\Contracts\SenderIdentityResolver;

/**
 * The one door every mail in this package leaves through.
 *
 * This one matters more than most: both mails here go to a member of the
 * public who just handed over an address. A confirmation that arrives under
 * another brand's name is not a configuration detail, it is the reader being asked to
 * trust a sender they never heard of.
 */
class BrandMailer extends BrandContextMailer
{
    public function __construct(SenderIdentityResolver $identities)
    {
        parent::__construct($identities);
    }
}
