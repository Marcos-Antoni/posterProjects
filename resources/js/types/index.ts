export type * from './auth';
export type * from './models';

/**
 * The mint response for `POST settings/mobile-token/qr`. `payload` (the
 * plaintext QR pass) rides on `live` only, and only here — no other
 * endpoint, including `QrPassStatus` below, ever returns it again
 * (design.md "Interfaces / Contracts").
 */
export type QrPassMint =
    | { state: 'live'; payload: string; expires_at: string }
    | { state: 'consumed'; consumed_at: string; consumed_ip: string | null };

/** The poll response for `GET settings/mobile-token/qr/status`. Never `payload`. */
export type QrPassStatus =
    | { state: 'live'; expires_at: string }
    | { state: 'consumed'; consumed_at: string; consumed_ip: string | null }
    | { state: 'expired' }
    | { state: 'none' };
