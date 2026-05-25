const LOGIN_URL = '/login';
const originalFetch = window.fetch.bind(window);

window.fetch = async (...args) => {
    const response = await originalFetch(...args);
    if (response.status === 401 && !isLoginRequest(args[0])) {
        const contentType = response.headers.get('Content-Type') || '';
        if (contentType.includes('application/json')) {
            redirectToLogin();
        }
    }
    return response;
};

function isLoginRequest(input) {
    try {
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        return url.includes(LOGIN_URL);
    } catch (_) {
        return false;
    }
}

function redirectToLogin() {
    const here = window.location.pathname + window.location.search;
    if (here.startsWith(LOGIN_URL)) {
        return;
    }
    window.location.assign(LOGIN_URL);
}
