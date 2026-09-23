import { h, render, toast } from '../ui.js';
import { unlockWithPin, currentUser, isReauthOverdue, logout } from '../auth.js';
import { navigate } from '../app.js';

export async function showUnlock() {
  const user = await currentUser();
  const overdue = await isReauthOverdue();

  const pin = h('input', {
    type: 'password', inputmode: 'numeric', pattern: '[0-9]*', name: 'pin',
    autocomplete: 'off', placeholder: '••••', maxlength: 12, class: 'pin-input',
  });
  const button = h('button', { type: 'submit', class: 'btn-primary' }, 'Unlock');

  const form = h(
    'form',
    {
      class: 'card',
      onsubmit: async (e) => {
        e.preventDefault();
        button.disabled = true;
        try {
          const res = await unlockWithPin(pin.value.trim());
          if (res.ok) {
            navigate('/');
          } else if (res.unavailable) {
            toast('Offline unlock is not available on this device — please log in online.', 'err');
            navigate('/login');
          } else {
            toast('Wrong PIN.', 'err');
            pin.value = '';
            button.disabled = false;
          }
        } catch (err) {
          toast('Could not verify PIN. Log in online instead.', 'err');
          button.disabled = false;
        }
      },
    },
    h('h1', {}, 'Enter PIN'),
    user ? h('p', { class: 'muted' }, `Signed in as ${user.full_name}`) : null,
    overdue
      ? h('div', { class: 'banner banner-warn' }, 'It has been over 7 days since your last online login. Unlock still works now, but log in online soon to keep syncing.')
      : null,
    h('label', {}, 'PIN', pin),
    button,
    h('button', {
      type: 'button', class: 'btn-link',
      onclick: async () => { await logout(); navigate('/login'); },
    }, 'Log in with email & password instead'),
  );

  const logo = h('img', { class: 'app-logo', src: './icons/icon-192.png', alt: 'ADWOL' });

  render(h('div', { class: 'auth-wrap' }, logo, form));
  pin.focus();
}
