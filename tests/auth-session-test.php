<?php
declare(strict_types=1);

function auth_session_expect(bool $condition, string $message): void
{
    if ($condition) return;
    throw new RuntimeException($message);
}

$auth = file_get_contents(dirname(__DIR__) . '/auth-runtime.php');
$home = file_get_contents(dirname(__DIR__) . '/store-home.js');
$login = file_get_contents(dirname(__DIR__) . '/index.php');

auth_session_expect(is_string($auth) && str_contains($auth, 'JG_ADMIN_SESSION_TTL_SECONDS = 7 * 24 * 60 * 60'), 'Store Ops sessions must last one week.');
auth_session_expect(str_contains((string) $auth, "ini_set('session.gc_maxlifetime'"), 'The server-side session lifetime must match the persistent cookie.');
auth_session_expect(str_contains((string) $auth, 'time() - $loginTimestamp >= JG_ADMIN_SESSION_TTL_SECONDS'), 'Weekly sessions must have an explicit server-side expiry.');
auth_session_expect(str_contains((string) $auth, 'A temporary profile-database outage must not eject an operator'), 'A transient employee-directory outage must preserve an already-authenticated fulfillment session.');
auth_session_expect(str_contains((string) $home, "await postOrderAction('claim_order', order)"), 'Starting an order must confirm authentication and the employee claim before opening fulfillment.');
auth_session_expect(!str_contains((string) $home, 'applyOptimisticClaim(order);'), 'Store Ops must not enter fulfillment optimistically before authentication succeeds.');
auth_session_expect(str_contains((string) $home, 'StoreOpsAuthenticationRequiredError') && str_contains((string) $home, 'redirectToStoreOpsLogin'), 'An expired start request must prompt for login before fulfillment.');
auth_session_expect(str_contains((string) $login, 'jg_admin_login_return_path') && str_contains((string) $login, "str_contains(\$path, '..')"), 'Login return paths must remain same-site and traversal-safe.');

echo "auth-session-test: ok\n";
