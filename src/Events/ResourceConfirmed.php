<?php

namespace Goldnead\LeadMagnets\Events;

/**
 * A pending grant became active.
 *
 * Fires exactly once per grant activation, guaranteed by the conditional
 * UPDATE in GrantService::activate() rather than by the caller being careful.
 * A queue retry, a double-clicked confirmation link and a mail scanner
 * prefetching the URL all produce the same single event.
 */
class ResourceConfirmed extends GrantEvent {}
