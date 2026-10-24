/**
 * auth.js  –  rKTs client-side session helper
 * Place in rkts/ alongside all HTML pages.
 *
 * Add to every page just before </body>, BEFORE any inline script that uses rktsAuth:
 *   <script src="auth.js"></script>
 *
 * The script:
 *   - Stores the session in sessionStorage (survives navigation, cleared on tab close)
 *   - Injects a user badge + logout link into the navbar automatically
 *   - Exposes rktsAuth.login(), .logout(), .getSession(), .requireLogin()
 */

const rktsAuth = (() => {

    const SESSION_KEY = 'rkts_session';

    // login.php lives in the same rkts/ folder as the HTML pages.
    // Build the URL relative to the current page's directory so it works
    // regardless of how the server is configured.
    const LOGIN_PHP = (function () {
        const parts = location.pathname.split('/');
        parts[parts.length - 1] = 'login.php';
        return parts.join('/');
    })();

    // ── Session ───────────────────────────────────────────────────────────────

    function getSession() {
        try { return JSON.parse(sessionStorage.getItem(SESSION_KEY)); }
        catch { return null; }
    }

    function setSession(data) {
        sessionStorage.setItem(SESSION_KEY, JSON.stringify(data));
    }

    function clearSession() {
        sessionStorage.removeItem(SESSION_KEY);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    async function login(username, password) {
        let res;
        try {
            res = await fetch(LOGIN_PHP, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
        } catch (networkErr) {
            throw new Error('Network error – is the server running?');
        }
        const data = await res.json();
        if (data.ok) {
            setSession({ user: data.user, level: data.level, email: data.email });
            return { ok: true };
        }
        return { ok: false, error: data.error || 'Login failed.' };
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    function logout() {
        clearSession();
        // Redirect to index in the same directory
        const parts = location.pathname.split('/');
        parts[parts.length - 1] = 'index.html';
        location.replace(parts.join('/'));
    }

    // ── Guard ─────────────────────────────────────────────────────────────────
    // Call rktsAuth.requireLogin() at the top of any protected page.
    // Call rktsAuth.requireLogin(1) to also require level <= 1 (editors).

    function requireLogin(maxLevel) {
        const sess = getSession();
        if (!sess) {
            const parts = location.pathname.split('/');
            parts[parts.length - 1] = 'login.html';
            location.replace(parts.join('/') + '?next=' + encodeURIComponent(location.pathname + location.search));
            return;
        }
        if (maxLevel !== undefined && sess.level > maxLevel) {
            const parts = location.pathname.split('/');
            parts[parts.length - 1] = 'index.html';
            location.replace(parts.join('/'));
        }
    }

    // ── Navbar injection ──────────────────────────────────────────────────────

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function injectNavbar() {
        const navUl = document.querySelector('.navbar-nav.ms-auto');
        if (!navUl) return;

        // Remove any previously injected item
        const existing = navUl.querySelector('.rkts-auth-nav');
        if (existing) existing.remove();

        const sess = getSession();
        const li = document.createElement('li');
        li.className = 'nav-item rkts-auth-nav';

        if (sess) {
            const badgeClass = sess.level <= 1 ? 'bg-primary' : 'bg-secondary';
            li.innerHTML =
                '<span class="nav-link d-flex align-items-center gap-2">' +
                  '<span class="badge ' + badgeClass + ' rounded-pill" title="Level ' + sess.level + '">' +
                    '<i class="bi bi-person-fill me-1"></i>' + escHtml(sess.user) +
                  '</span>' +
                  '<a href="#" class="rkts-logout-btn text-muted small text-decoration-none" title="Sign out">' +
                    '<i class="bi bi-box-arrow-right"></i>' +
                  '</a>' +
                '</span>';
            li.querySelector('.rkts-logout-btn').addEventListener('click', function (e) {
                e.preventDefault();
                logout();
            });
        } else {
            li.innerHTML =
                '<a class="nav-link" href="login.html">' +
                  '<i class="bi bi-box-arrow-in-right me-1"></i>Sign in' +
                '</a>';
        }

        navUl.appendChild(li);
    }

    // Run after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectNavbar);
    } else {
        injectNavbar();
    }

    // Public API
    return { login, logout, getSession, setSession, requireLogin };

})();