<?php

namespace zFramework\Core\Helpers\cPanel;

/**
 * cPanel email account and forwarder management via UAPI.
 */
class Email
{
    /**
     * List all email accounts (POP3 mailboxes) on the account.
     * Each entry includes: email, quota, diskused, _diskquota, etc.
     */
    public static function list(): ?array
    {
        return API::request("Email/list_pops");
    }

    /**
     * Create a new email account.
     *
     * @param string $email     Full email address (e.g. "info@example.com")
     * @param string $password  Mailbox password
     * @param int    $quota     Mailbox quota in MB, default 250 MB. Pass 0 for unlimited.
     */
    public static function create(string $email, string $password, int $quota = 250): ?array
    {
        return API::request("Email/add_pop", compact('email', 'password', 'quota'));
    }

    /**
     * Change the password of an existing email account.
     *
     * @param string $email     Full email address (e.g. "info@example.com")
     * @param string $password  New password
     */
    public static function changePassword(string $email, string $password): ?array
    {
        return API::request("Email/passwd_pop", compact('email', 'password'));
    }

    /**
     * Delete an email account. Emails stored in the mailbox are permanently deleted.
     *
     * @param string $user  Full email address to delete (e.g. "info@example.com")
     */
    public static function delete(string $user): ?array
    {
        return API::request("Email/delete_pop", ["email" => $user]);
    }

    // ── Forwarders ──────────────────────────────────────────────────────────

    /**
     * List all email forwarders on the account.
     * Each entry includes: dest (destination), forward (source address).
     */
    public static function listForwarders(): ?array
    {
        return API::request("Email/list_forwarders");
    }

    /**
     * Create an email forwarder — incoming mail to $email is forwarded to $destination.
     *
     * @param string $email        Source email address (e.g. "contact@example.com")
     * @param string $destination  Destination email address to forward to
     */
    public static function addForwarder(string $email, string $destination): ?array
    {
        return API::request("Email/add_forwarder", [
            "email"   => $email,
            "fwdest" => $destination
        ]);
    }

    /**
     * Delete an email forwarder.
     *
     * @param string $email        Source email address of the forwarder
     * @param string $destination  Destination address of the forwarder to remove
     */
    public static function deleteForwarder(string $email, string $destination): ?array
    {
        return API::request("Email/delete_forwarder", [
            "email"   => $email,
            "fwdest" => $destination
        ]);
    }
}
