<?php

declare(strict_types=1);

namespace Rajdhani\Support;

/**
 * Where an identity provider's public signing keys come from.
 *
 * An interface rather than a direct HTTP call so the verifier can be tested
 * against a locally generated key pair. Without it, testing signature
 * verification would mean either calling Google from the test suite — slow,
 * offline-hostile, and untestable for the failure cases — or not testing the
 * signature check at all, which is the one part that must not be wrong.
 */
interface JwkSource
{
    /**
     * @param bool $forceRefresh bypass any cache; used once after a key id miss,
     *                           because providers rotate keys without warning
     *
     * @return list<array<string,mixed>> the JWKS `keys` array
     */
    public function keys(bool $forceRefresh = false): array;
}
