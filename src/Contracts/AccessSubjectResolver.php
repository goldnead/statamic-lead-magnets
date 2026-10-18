<?php

namespace Goldnead\LeadMagnets\Contracts;

use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;

/**
 * The host's answer to "who is this address, as an account".
 *
 * A resource of type `access` does not hand out a file. It opens a course or a
 * piece of material that belongs to a signed-in person, and access of that kind
 * is checked against an account (`Entitlements::allows($user, $product)`), not
 * against the hash this addon uses for a file reader. Identity stays with the
 * host application, exactly as the README says: this addon never creates a
 * user, it asks the host to.
 *
 * Both methods run after the address has been confirmed, never before: an
 * unconfirmed address gets no account.
 */
interface AccessSubjectResolver
{
    /**
     * Find or create the account behind a confirmed address and return what
     * entitlements takes as a subject: a model, or a `SubjectReference`.
     *
     * Must be safe to call twice for the same address.
     */
    public function subjectFor(string $email, Grant $grant): mixed;

    /**
     * A link that signs the subject in and lands on the access, or null when
     * the host offers none (the reader then signs in the usual way).
     *
     * `$resource->access_path` is the landing path the editor chose, when set.
     */
    public function accessUrl(mixed $subject, Resource $resource, Grant $grant): ?string;
}
