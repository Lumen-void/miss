document.addEventListener('submit', (event) => {
  const button = event.target.querySelector('button');
  if (!button || button.dataset.busy === '1') return;
  button.dataset.busy = '1';
  button.textContent = button.textContent.includes('Upload') ? 'Importing...' : 'Working...';
});

const autoLog = document.querySelector('#auto-job-log[data-job-id]');
if (autoLog) {
  const jobId = autoLog.dataset.jobId;
  const events = autoLog.querySelector('.auto-events');
  let stopped = false;
  const poll = async () => {
    if (stopped) return;
    try {
      const response = await fetch(`${window.location.pathname.replace(/\/auto-import$/, '/auto-import/status')}?job_id=${encodeURIComponent(jobId)}`, {
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (events && data.events_html) {
        events.innerHTML = data.events_html;
      }
      const status = data.job?.status || '';
      if (['completed', 'needs_attention', 'failed'].includes(status)) {
        stopped = true;
        return;
      }
    } catch {
      // The page remains usable even if a single polling request fails.
    }
    window.setTimeout(poll, 4000);
  };
  window.setTimeout(poll, 1200);
}

const portalConnect = document.querySelector('#portal-connect[data-source]');
if (portalConnect) {
  const source = portalConnect.dataset.source;
  const statusUrl = portalConnect.dataset.statusUrl || `${window.location.pathname.replace(/\/portal\/connect$/, '/portal/status')}?source=${encodeURIComponent(source)}`;
  const badge = portalConnect.querySelector('[data-connect-badge]');
  const title = portalConnect.querySelector('[data-connect-title]');
  const message = portalConnect.querySelector('[data-connect-message]');
  const fetchButton = portalConnect.querySelector('[data-connect-fetch]');
  let attempts = 0;

  const renderStatus = (data) => {
    const status = data.status || 'not_connected';
    const connected = status === 'connected';
    if (badge) {
      badge.className = `connection-badge ${data.status_class || (connected ? 'connected' : 'pending')}`;
      badge.textContent = data.status_label || (connected ? 'Connected' : (status === 'login_required' ? 'Login required' : 'Checking'));
    }
    if (title) {
      title.textContent = connected
        ? 'Connected and saved'
        : (status === 'otp_required' ? 'OTP required in Chrome' : (status === 'login_required' ? 'Login required in Chrome' : 'Checking saved Chrome session...'));
    }
    if (message) {
      message.textContent = connected
        ? 'This portal session is saved. You can fetch reports without connecting again until the portal expires the login.'
        : (data.message || 'Complete login or OTP in the Chrome window. This page will keep checking.');
    }
    if (fetchButton) {
      fetchButton.disabled = !connected;
      fetchButton.textContent = connected ? 'Fetch report now' : 'Waiting for connection';
    }
    return connected;
  };

  const pollConnection = async () => {
    attempts += 1;
    try {
      const separator = statusUrl.includes('?') ? '&' : '?';
      const response = await fetch(`${statusUrl}${separator}verify=1`, {
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (renderStatus(data) || attempts >= 10) {
        return;
      }
    } catch {
      if (message) message.textContent = 'Could not check connection yet. Keep the portal window open and try Verify connection now.';
    }
    window.setTimeout(pollConnection, 20000);
  };

  window.setTimeout(pollConnection, 1500);
}

const connectAll = document.querySelector('#portal-connect-all');
if (connectAll) {
  const cards = Array.from(connectAll.querySelectorAll('[data-portal-card]'));
  const verifyAll = connectAll.querySelector('[data-verify-all]');
  const fetchConnected = connectAll.querySelector('[data-fetch-connected]');
  let attempts = 0;

  const updateFetchButton = () => {
    const connectedCount = cards.filter((card) => card.querySelector('[data-source-checkbox]')?.checked).length;
    if (fetchConnected) {
      fetchConnected.disabled = connectedCount === 0;
      fetchConnected.textContent = connectedCount > 0 ? `Fetch ${connectedCount} connected portal${connectedCount === 1 ? '' : 's'}` : 'Waiting for connected portals';
    }
  };

  const renderCard = (card, data) => {
    const status = data.status || 'not_connected';
    const connected = status === 'connected';
    const badge = card.querySelector('[data-status-badge]');
    const message = card.querySelector('[data-status-message]');
    const checkbox = card.querySelector('[data-source-checkbox]');
    if (badge) {
      badge.className = `connection-badge ${data.status_class || (connected ? 'connected' : 'pending')}`;
      badge.textContent = data.status_label || (connected ? 'Connected' : 'Login required');
    }
    if (message) {
      message.textContent = connected
        ? 'Connected. This source is ready for report fetch.'
        : (data.message || 'Complete login or OTP in Chrome, then keep this page open.');
    }
    if (checkbox) {
      checkbox.checked = connected;
      checkbox.disabled = !connected;
    }
  };

  const pollCards = async (force = false) => {
    attempts += 1;
    await Promise.all(cards.map(async (card) => {
      const statusUrl = card.dataset.statusUrl;
      if (!statusUrl) return;
      try {
        const separator = statusUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${statusUrl}${separator}verify=1`, {
          headers: { Accept: 'application/json' },
        });
        renderCard(card, await response.json());
      } catch {
        const message = card.querySelector('[data-status-message]');
        if (message) message.textContent = 'Could not check this portal yet. Keep Chrome open and verify again.';
      }
    }));
    updateFetchButton();
    const allConnected = cards.every((card) => card.querySelector('[data-source-checkbox]')?.checked);
    if (!allConnected && (force || attempts < 20)) {
      window.setTimeout(() => pollCards(false), 15000);
    }
  };

  if (verifyAll) {
    verifyAll.addEventListener('click', () => {
      attempts = 0;
      pollCards(true);
    });
  }
  cards.forEach((card) => card.querySelector('[data-source-checkbox]')?.addEventListener('change', updateFetchButton));
  updateFetchButton();
  window.setTimeout(() => pollCards(false), 1200);
}

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const navToggle = document.querySelector('[data-nav-toggle]');
const navClose = document.querySelector('[data-nav-close]');
if (navToggle) {
  navToggle.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
}
if (navClose) {
  navClose.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
}

document.querySelectorAll('.panel, .hero, .mis-hero, .stats, .workflow, .subnav, .readiness-panel').forEach((element) => {
  if (!element.hasAttribute('data-reveal')) element.setAttribute('data-reveal', '');
});

const revealTargets = Array.from(document.querySelectorAll('[data-reveal]'));
if (reducedMotion) {
  revealTargets.forEach((element) => element.classList.add('is-visible'));
} else if ('IntersectionObserver' in window) {
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      revealObserver.unobserve(entry.target);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
  revealTargets.forEach((element) => revealObserver.observe(element));
} else {
  revealTargets.forEach((element) => element.classList.add('is-visible'));
}

const parseNumericText = (text) => {
  const trimmed = text.trim();
  if (!trimmed || /[A-Za-z]{2,}|[-/]/.test(trimmed.replace(/[₹,%.\s]/g, ''))) return null;
  const number = Number(trimmed.replace(/[^0-9.-]/g, ''));
  if (!Number.isFinite(number)) return null;
  return {
    value: number,
    prefix: trimmed.startsWith('₹') ? '₹' : '',
    suffix: trimmed.endsWith('%') ? '%' : '',
    decimals: trimmed.includes('.') ? 2 : 0,
  };
};

const formatCount = ({ value, prefix, suffix, decimals }) => {
  const options = { maximumFractionDigits: decimals, minimumFractionDigits: decimals };
  return `${prefix}${value.toLocaleString('en-IN', options)}${suffix}`;
};

const countTargets = Array.from(document.querySelectorAll('[data-count], .metric-card strong, .mis-command-card strong, .visual-summary-card strong, .quality-card strong'))
  .filter((element) => parseNumericText(element.textContent || ''));

const animateCount = (element) => {
  const parsed = parseNumericText(element.textContent || '');
  if (!parsed || element.dataset.counted === '1') return;
  element.dataset.counted = '1';
  if (reducedMotion) {
    element.textContent = formatCount(parsed);
    return;
  }
  const duration = 900;
  const start = performance.now();
  const target = parsed.value;
  const tick = (now) => {
    const progress = Math.min(1, (now - start) / duration);
    const eased = 1 - Math.pow(1 - progress, 3);
    element.textContent = formatCount({ ...parsed, value: target * eased });
    if (progress < 1) requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
};

if ('IntersectionObserver' in window) {
  const countObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      animateCount(entry.target);
      countObserver.unobserve(entry.target);
    });
  }, { threshold: 0.4 });
  countTargets.forEach((element) => countObserver.observe(element));
} else {
  countTargets.forEach(animateCount);
}

const chartTargets = Array.from(document.querySelectorAll('[data-chart]'));
const drawChart = (chart) => {
  chart.classList.add('chart-drawn');
  chart.querySelectorAll('[data-bar-fill]').forEach((bar) => {
    const target = bar.style.getPropertyValue('--target-width') || bar.style.width || '0%';
    if (!reducedMotion) bar.style.width = '0%';
    window.requestAnimationFrame(() => {
      bar.style.width = target;
    });
  });
};

if ('IntersectionObserver' in window) {
  const chartObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      drawChart(entry.target);
      chartObserver.unobserve(entry.target);
    });
  }, { threshold: 0.25 });
  chartTargets.forEach((chart) => chartObserver.observe(chart));
} else {
  chartTargets.forEach(drawChart);
}

document.querySelectorAll('form[action*="/runs/finalize"], form[action*="/runs/unlock"], form[action*="/adjustments/delete"], form[action*="/auto-import/stop"]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const action = form.getAttribute('action') || '';
    const message = action.includes('/runs/finalize')
      ? 'Finalize and lock this MIS run? Imports and adjustments will be protected until unlocked.'
      : action.includes('/runs/unlock')
        ? 'Unlock this MIS run for editing?'
        : action.includes('/auto-import/stop')
          ? 'Stop the running auto-import job?'
          : 'Delete this item?';
    if (!window.confirm(message)) {
      event.preventDefault();
    }
  });
});
