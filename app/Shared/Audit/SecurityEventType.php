<?php

namespace App\Shared\Audit;

enum SecurityEventType: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case LoginBlocked = 'login_blocked';
    case AccountLocked = 'account_locked';
    case AccountUnlocked = 'account_unlocked';
    case Logout = 'logout';
    case MfaChallengeIssued = 'mfa_challenge_issued';
    case MfaChallengeFailed = 'mfa_challenge_failed';
    case MfaChallengePassed = 'mfa_challenge_passed';
    case MfaMethodAdded = 'mfa_method_added';
    case MfaMethodRemoved = 'mfa_method_removed';
    case RecoveryCodeUsed = 'recovery_code_used';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case TrustedDeviceAdded = 'trusted_device_added';
    case TrustedDeviceRevoked = 'trusted_device_revoked';
    case PasswordChanged = 'password_changed';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
    case PasswordResetByAdmin = 'password_reset_by_admin';
    case SessionRevoked = 'session_revoked';
    case UserCreated = 'user_created';
    case UserActivated = 'user_activated';
    case UserDeactivated = 'user_deactivated';
    case EmailVerified = 'email_verified';
    case RoleAssigned = 'role_assigned';
    case RoleRevoked = 'role_revoked';
    case RolePermissionsChanged = 'role_permissions_changed';
    case SettingsChanged = 'settings_changed';
    case ConfidentialFileDownloaded = 'confidential_file_downloaded';
    case AuditExported = 'audit_exported';
    case InstitutionSuspended = 'institution_suspended';
    case InstitutionReactivated = 'institution_reactivated';

    public function severity(): string
    {
        return match ($this) {
            self::LoginFailed, self::MfaChallengeFailed, self::PasswordResetByAdmin,
            self::RoleAssigned, self::RoleRevoked, self::RolePermissionsChanged,
            self::SettingsChanged, self::RecoveryCodeUsed, self::MfaMethodRemoved => 'warning',
            self::AccountLocked, self::LoginBlocked, self::InstitutionSuspended => 'critical',
            default => 'info',
        };
    }
}
