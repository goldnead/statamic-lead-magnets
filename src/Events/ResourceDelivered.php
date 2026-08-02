<?php

namespace Goldnead\LeadMagnets\Events;

/**
 * The delivery mail carrying the signed download link went out.
 *
 * Separate from ResourceConfirmed because the two can come apart: a
 * suppressed address is confirmed and never delivered to, and that difference
 * is the whole reason the suppression bridge exists.
 */
class ResourceDelivered extends GrantEvent {}
