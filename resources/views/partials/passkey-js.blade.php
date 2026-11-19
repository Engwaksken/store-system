{{-- WebAuthn helpers shared by the sign-in page and the Sign-in & security page. --}}
<script>
window.Passkey = (function () {
    const csrf = @json(csrf_token());
    const supported = !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
    const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const unb64 = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), c => c.charCodeAt(0));

    async function post(url, body) {
        const res = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify(body || {}),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            const first = json.errors ? Object.values(json.errors)[0] : null;
            throw new Error((first && first[0]) || json.message || 'Something went wrong. Try again.');
        }
        return json;
    }

    function friendly(e) {
        if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return 'Cancelled or timed out. Try again when you are ready.';
        if (e && e.name === 'InvalidStateError') return 'This device already has a passkey for your account.';
        if (e && e.name === 'SecurityError') return 'Passkeys need the site to be opened over https.';
        return (e && e.message) || 'Something went wrong. Try again.';
    }

    async function signIn(optionsUrl, loginUrl, remember) {
        const o = await post(optionsUrl);
        const cred = await navigator.credentials.get({publicKey: {
            challenge: unb64(o.challenge), rpId: o.rpId, timeout: o.timeout, userVerification: o.userVerification,
            allowCredentials: (o.allowCredentials || []).map(c => ({type: 'public-key', id: unb64(c.id)})),
        }});
        return post(loginUrl, {remember: !!remember, credential: {
            id: cred.id, rawId: b64(cred.rawId), type: cred.type,
            response: {
                clientDataJSON: b64(cred.response.clientDataJSON),
                authenticatorData: b64(cred.response.authenticatorData),
                signature: b64(cred.response.signature),
                userHandle: cred.response.userHandle ? b64(cred.response.userHandle) : null,
            },
        }});
    }

    async function register(optionsUrl, storeUrl, password, name) {
        const o = await post(optionsUrl, {current_password: password});
        const cred = await navigator.credentials.create({publicKey: {
            ...o, challenge: unb64(o.challenge), user: {...o.user, id: unb64(o.user.id)},
            excludeCredentials: (o.excludeCredentials || []).map(c => ({type: 'public-key', id: unb64(c.id)})),
        }});
        return post(storeUrl, {name: name, credential: {
            id: cred.id, rawId: b64(cred.rawId), type: cred.type,
            response: {clientDataJSON: b64(cred.response.clientDataJSON), attestationObject: b64(cred.response.attestationObject)},
        }});
    }

    return {supported, signIn, register, friendly};
})();
</script>
