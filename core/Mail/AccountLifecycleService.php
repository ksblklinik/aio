<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Database\Connection;
use RuntimeException;

class AccountLifecycleService
{
    public function __construct(
        protected Connection $db,
        protected Mailer $mailer
    ) {
    }

    /**
     * Create an invitation to join a workspace and queue an invitation email.
     *
     * @return string The generated secure invitation token
     */
    public function inviteMember(
        int $tenantId,
        string $email,
        string $role = 'member',
        ?int $inviterId = null,
        int $expiresInDays = 7
    ): string {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresInDays * 86400));

        $this->db->insert('workspace_invitations', [
            'tenant_id' => $tenantId,
            'email' => strtolower(trim($email)),
            'role' => $role,
            'token' => $token,
            'inviter_id' => $inviterId,
            'expires_at' => $expiresAt,
        ]);

        $tenantRow = $this->db->selectOne("SELECT name FROM tenants WHERE id = ?", [$tenantId]);
        $tenantName = $tenantRow ? (string) $tenantRow['name'] : 'Workspace';

        $inviteUrl = (string) (config('app.url') ?: 'http://localhost:8000') . "/invitations/accept?token={$token}";

        $message = (new Message())
            ->to($email)
            ->subject("Undangan Bergabung ke {$tenantName}")
            ->html(<<<HTML
<p>Halo,</p>
<p>Anda telah diundang untuk bergabung ke workspace <strong>{$tenantName}</strong> dengan peran <em>{$role}</em>.</p>
<p><a href="{$inviteUrl}" style="background:#3b82f6;color:#ffffff;padding:10px 16px;text-decoration:none;border-radius:4px;display:inline-block;">Terima Undangan</a></p>
<p>Tautan ini berlaku hingga {$expiresAt}.</p>
HTML
            );

        $this->mailer->queue($message);

        return $token;
    }

    /**
     * Verify if an invitation token is valid and unexpired.
     *
     * @return array<string, mixed>|null
     */
    public function verifyInvitation(string $token): ?array
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->selectOne(
            "SELECT * FROM workspace_invitations WHERE token = ? AND accepted_at IS NULL AND expires_at > ? LIMIT 1",
            [$token, $now]
        );
    }

    /**
     * Accept a workspace invitation and bind the user to the tenant.
     */
    public function acceptInvitation(string $token, int $userId): bool
    {
        $invitation = $this->verifyInvitation($token);
        if (!$invitation) {
            return false;
        }

        $tenantId = (int) $invitation['tenant_id'];
        $role = (string) ($invitation['role'] ?? 'member');

        $existing = $this->db->selectOne(
            "SELECT id FROM tenant_user WHERE tenant_id = ? AND user_id = ?",
            [$tenantId, $userId]
        );

        if (!$existing) {
            $this->db->insert('tenant_user', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role' => $role,
            ]);
        }

        $this->db->statement(
            "UPDATE workspace_invitations SET accepted_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$invitation['id']]
        );

        return true;
    }

    /**
     * Generate password reset token and queue password reset email.
     */
    public function sendPasswordResetLink(string $email, int $expiresInHours = 2): ?string
    {
        $cleanEmail = strtolower(trim($email));
        $user = $this->db->selectOne("SELECT id, name FROM users WHERE email = ? LIMIT 1", [$cleanEmail]);

        if (!$user) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresInHours * 3600));

        $this->db->insert('password_resets', [
            'email' => $cleanEmail,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        $resetUrl = (string) (config('app.url') ?: 'http://localhost:8000') . "/reset-password?token={$token}";

        $message = (new Message())
            ->to($cleanEmail)
            ->subject("Instruksi Reset Password Akun")
            ->html(<<<HTML
<p>Halo {$user['name']},</p>
<p>Kami menerima permintaan untuk mereset kata sandi akun Anda. Klik tombol di bawah ini untuk melanjutkan:</p>
<p><a href="{$resetUrl}" style="background:#ef4444;color:#ffffff;padding:10px 16px;text-decoration:none;border-radius:4px;display:inline-block;">Reset Password</a></p>
<p>Tautan ini hanya berlaku selama {$expiresInHours} jam. Abaikan email ini jika Anda tidak meminta reset password.</p>
HTML
            );

        $this->mailer->queue($message);

        return $token;
    }

    /**
     * Verify password reset token.
     */
    public function verifyPasswordResetToken(string $token): ?string
    {
        $now = date('Y-m-d H:i:s');
        $row = $this->db->selectOne(
            "SELECT email FROM password_resets WHERE token = ? AND expires_at > ? ORDER BY id DESC LIMIT 1",
            [$token, $now]
        );

        return $row ? (string) $row['email'] : null;
    }

    /**
     * Complete password reset.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $email = $this->verifyPasswordResetToken($token);
        if (!$email) {
            return false;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->statement(
            "UPDATE users SET password_hash = ? WHERE email = ?",
            [$hash, $email]
        );

        // Invalidate token
        $this->db->statement(
            "DELETE FROM password_resets WHERE email = ?",
            [$email]
        );

        return true;
    }
}
