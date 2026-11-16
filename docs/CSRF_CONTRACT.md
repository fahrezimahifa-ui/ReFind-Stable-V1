# CSRF and session contract

One canonical token lives in Laravel `_token`. ReFindSessionManager uses Laravel's file handler with ReFindSessionStore. Its start/regenerate paths generate 32 random bytes encoded as exactly 64 lowercase hex characters; session IDs retain Laravel's native format.

The API publishes this token as `csrf`; `csrf_token()` resolves the same store. Existing JavaScript sends X-CSRF-Token; the optional form alias is `_csrf`. Conflicting header/body tokens fail. `_token` body and X-XSRF-TOKEN are not extra public compatibility channels, and there is no XSRF-TOKEN cookie.

ReFindCsrfMiddleware replaces the default web forgery middleware. Its handle method has no unit-test/console or Sec-Fetch-Site bypass. Supported mutations always validate the token and any supplied Origin. Identity/role/precondition checks preserve native precedence. Failures use 403 csrf_invalid.

Login regenerates identity and token, logout invalidates identity and generates guest state, and expiry/fingerprint checks invalidate stale identities. No native PHP sessions or keys are shared. Password-change behavior remains for the later account phase.

PHPUnit runs with REFIND_PROFILE=test and APP_ENV=testing, leaves middleware enabled and proves missing/same-origin/conflicting tokens fail. The HTTP suite uses actual cookies; independent HTTP processes demonstrate shared session blocking and reject an old authenticated cookie after concurrent logout.
