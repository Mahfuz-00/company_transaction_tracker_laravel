import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * LIVE INVITE-CODE VALIDATION.
 *
 * The registration screen must not unlock SSO/OAuth until the code the user
 * typed actually resolves to a real, active institution. This hook performs that
 * check against `POST /register/validate-invite-code`
 * (App\Http\Controllers\Auth\InviteCodeCheckController).
 *
 * WHY A SERVER ROUND-TRIP AND NOT A CLIENT-SIDE FORMAT CHECK
 *   Only the server knows which codes exist. A length/format check would unlock
 *   the provider buttons for `NOPE9999`, and the user would complete an entire
 *   OAuth round-trip before being rejected on the way back. Confirming first is
 *   the difference between a gate and a decoration.
 *
 * STATE MACHINE
 *   status: 'idle' | 'checking' | 'valid' | 'invalid'
 *
 *   idle     — nothing typed (or erased). SSO stays locked, no error shown.
 *   checking — a request is in flight. SSO stays locked (never unlock on stale
 *              state, or a fast typist could click through a code they replaced).
 *   valid    — the server confirmed it. SSO unlocks; the institution name is
 *              available so the UI can say "✓ Matched: <name>".
 *   invalid  — the server refused it. SSO stays locked and a message is shown.
 *
 * DESIGN NOTES
 *   - DEBOUNCED (450ms): a request per keystroke would burn the 12/min throttle
 *     in under two seconds of typing.
 *   - RACE-SAFE: each request carries a monotonically increasing id and only the
 *     newest response is allowed to write state. Without this, a slow response
 *     for an old code can land after a fast one for the new code and resurrect a
 *     stale "valid", unlocking SSO for a code the user has since changed.
 *   - ABORTED on unmount / re-run via an AbortController, so a component that
 *     unmounts mid-flight cannot set state on a dead component.
 *
 * @param {string} code - the invite code currently typed
 * @returns {{ status: string, institutionName: string|null, message: string|null, isValid: boolean }}
 */
export default function useInviteCodeValidation(code) {
    const [status, setStatus] = useState('idle');
    const [institutionName, setInstitutionName] = useState(null);

    // Monotonic request id — the race guard described above.
    const requestId = useRef(0);

    // The debounce timer, so a fast typist does not fire a request per keypress.
    const timer = useRef(null);

    // The in-flight request's abort handle.
    const controller = useRef(null);

    const check = useCallback(async (value) => {
        const id = ++requestId.current;

        controller.current?.abort();
        controller.current = new AbortController();

        setStatus('checking');

        try {
            const { data } = await axios.post(
                route('register.invite-code.check'),
                { invite_code: value },
                { signal: controller.current.signal }
            );

            // Discard a response that is no longer the newest request.
            if (id !== requestId.current) return;

            if (data.valid) {
                setInstitutionName(data.institution_name ?? null);
                setStatus('valid');
            } else {
                setInstitutionName(null);
                setStatus('invalid');
            }
        } catch (error) {
            // An abort is expected (the user kept typing) — not a failure.
            if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') return;
            if (id !== requestId.current) return;

            // A validation/network failure must LOCK SSO, never unlock it.
            setInstitutionName(null);
            setStatus('invalid');
        }
    }, []);

    useEffect(() => {
        const value = (code || '').trim();

        // Clear any pending work from the previous value.
        if (timer.current) clearTimeout(timer.current);

        if (value.length === 0) {
            requestId.current += 1; // invalidate any in-flight response
            controller.current?.abort();
            setStatus('idle');
            setInstitutionName(null);

            return undefined;
        }

        timer.current = setTimeout(() => check(value), 450);

        return () => {
            if (timer.current) clearTimeout(timer.current);
        };
    }, [code, check]);

    // Abort on unmount so no state is set on a dead component.
    useEffect(() => () => controller.current?.abort(), []);

    return {
        status,
        institutionName,
        isValid: status === 'valid',
        message:
            status === 'invalid'
                ? 'That invitation code is not valid. Ask your institution admin for the correct code.'
                : null,
    };
}