<?php

namespace Goldnead\LeadMagnets\Events;

/**
 * A visitor asked for a resource.
 *
 * Fires for every accepted request, including a repeat one for a grant that is
 * already active — asking again is a real signal and swallowing it would make
 * the attribution wrong. What does not fire again is the confirmation.
 */
class ResourceRequested extends GrantEvent {}
