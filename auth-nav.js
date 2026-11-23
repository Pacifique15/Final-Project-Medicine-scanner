(function () {
  const nativeFetch = window.fetch.bind(window);
  const publicActions = new Set([
    'auth_token', 'auth_me', 'auth_login', 'auth_register', 'auth_logout',
    'auth_reset_request', 'auth_reset_password'
  ]);
  window.fetch = async function (...args) {
    const response = await nativeFetch(...args);
    const requestUrl = args[0] instanceof Request ? args[0].url : String(args[0]);
    const url = new URL(requestUrl, location.href);
    if (response.status === 401 && /\/api\.php$/i.test(url.pathname) &&
        !publicActions.has(url.searchParams.get('action'))) {
      location.replace('account.html');
    }
    return response;
  };

  const nav = document.querySelector('.nav nav');
  if (!nav) return;
  const page = location.pathname.split('/').pop() || 'index.html';

  async function getAccount() {
    const response = await fetch('api.php?action=auth_me', { cache: 'no-store' });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Could not verify the current session.');
    return result.account;
  }

  async function renderNavigation() {
    let account;
    try {
      account = await getAccount();
    } catch (error) {
      if (page !== 'account.html') location.replace('account.html');
      else nav.hidden = true;
      return;
    }
    if (!account) {
      if (page !== 'account.html') location.replace('account.html');
      else nav.hidden = true;
      return;
    }

    const links = [
      ['index.html', 'Home'],
      ['about.html', 'About'],
      ['chatbot.html', 'Chatbot'],
      ['booking.html', 'Appointments'],
      ['report.html', 'Report'],
      ['account.html', 'My Profile']
    ];
    nav.innerHTML = links.map(([href, label]) => {
      const active = href.split('#')[0] === page;
      return '<a href="' + href + '"' + (active ? ' class="active"' : '') + '>' + label + '</a>';
    }).join('') + '<a href="account.html" data-auth-logout>Logout</a>';
    nav.hidden = false;
  }

  nav.addEventListener('click', async event => {
    if (!event.target.matches('[data-auth-logout]')) return;
    event.preventDefault();
    const logoutLink = event.target;
    logoutLink.setAttribute('aria-busy', 'true');
    try {
      const tokenResponse = await fetch('api.php?action=auth_token', { cache: 'no-store' });
      const tokenResult = await tokenResponse.json();
      if (!tokenResponse.ok || !tokenResult.ok) throw new Error(tokenResult.error || 'Could not validate this session.');
      const response = await fetch('api.php?action=auth_logout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: tokenResult.csrf_token })
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Could not sign out.');
      location.replace('account.html');
    } catch (error) {
      logoutLink.removeAttribute('aria-busy');
      window.alert(error.message);
    }
  });

  window.addEventListener('pageshow', event => {
    if (event.persisted) renderNavigation();
  });
  window.setInterval(() => {
    if (!document.hidden) renderNavigation();
  }, 60000);
  renderNavigation();
})();
