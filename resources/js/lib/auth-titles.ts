import { t } from '@/lib/i18n';

/**
 * The titles and descriptions of the sign-in pages are passed to the auth
 * layouts as plain text and translated there; listing them here keeps them
 * in the translation files.
 */
export const authTitles = (): string[] => [
    t('14 days free, no card needed. You will confirm your email next.'),
    t('Authentication code'),
    t('Confirm password'),
    t('Create your hotel account'),
    t('Email verification'),
    t(
        'Enter the authentication code provided by your authenticator application.',
    ),
    t('Enter your email and password below to log in'),
    t('Enter your email to receive a password reset link'),
    t('Forgot password'),
    t('Log in to your account'),
    t(
        'Please confirm access to your account by entering one of your emergency recovery codes.',
    ),
    t('Please enter your new password below'),
    t('Please read and accept the current versions to continue.'),
    t(
        'Please verify your email address by clicking on the link we just emailed to you.',
    ),
    t('Recovery code'),
    t('Reply address'),
    t('Reset password'),
    t(
        'This is a secure area of the application. Please confirm your password before continuing.',
    ),
    t('Updated terms'),
];
