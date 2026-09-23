import { h, render, toast } from '../ui.js';
import { loginOnline } from '../auth.js';
import { OfflineError } from '../api.js';
import { navigate } from '../app.js';

export function showLogin() {
  const email = h('input', { type: 'email', name: 'email', required: true, autocomplete: 'username', placeholder: 'you@agency.gov' });
  const password = h('input', { type: 'password', name: 'password', required: true, autocomplete: 'current-password', placeholder: 'Password' });
  const button = h('button', { type: 'submit', class: 'btn-primary' }, 'Log in');

  const form = h(
    'form',
    {
      class: 'card',
      onsubmit: async (e) => {
        e.preventDefault();
        button.disabled = true;
        button.textContent = 'Logging in…';
        try {
          await loginOnline(email.value.trim(), password.value);
          navigate('/');
        } catch (err) {
          if (err instanceof OfflineError) {
            toast('No connection — you need to be online for the first login.', 'err');
          } else if (err.code === 'not_inspector') {
            toast(err.message, 'err');
          } else if (err.status === 429) {
            toast('Too many attempts. Wait a few minutes and try again.', 'err');
          } else {
            toast('Email or password is incorrect.', 'err');
          }
          button.disabled = false;
          button.textContent = 'Log in';
        }
      },
    },
    h('h1', {}, 'Field Inspection'),
    h('p', { class: 'muted' }, 'Sign in to download your assigned inspections. After that the app works offline.'),
    h('label', {}, 'Email', email),
    h('label', {}, 'Password', password),
    button,
  );

  const logo = h('img', { class: 'app-logo', src: './icons/icon-192.png', alt: 'ADWOL' });

  render(h('div', { class: 'auth-wrap' }, logo, form));
}
