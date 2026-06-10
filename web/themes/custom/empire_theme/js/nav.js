/**
 * Empire nav — mobile menu toggle + front-page scroll-to-opaque.
 * Presentation only; native scroll/keyboard otherwise.
 */
((Drupal) => {
  Drupal.behaviors.empireNav = {
    attach() {
      const nav = document.querySelector('[data-empire-nav]');
      if (!nav || nav.dataset.empireNavBound) {
        return;
      }
      nav.dataset.empireNavBound = '1';

      // Mobile menu toggle (collapse).
      const toggle = nav.querySelector('[data-empire-nav-toggle]');
      const menu = nav.querySelector('#empire-nav-menu');
      if (toggle && menu) {
        // Keep Tab inside the open overlay so keyboard users can't reach the
        // page chrome behind it (toggle is the close control, so it's included).
        const focusables = () => [
          toggle,
          ...menu.querySelectorAll('a[href], button:not([disabled])'),
        ];
        const trap = (e) => {
          if (e.key !== 'Tab') {
            return;
          }
          const items = focusables();
          if (!items.length) {
            return;
          }
          const first = items[0];
          const last = items[items.length - 1];
          if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
          }
          else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
          }
        };
        const close = () => {
          menu.removeAttribute('data-open');
          toggle.setAttribute('aria-expanded', 'false');
          menu.removeAttribute('role');
          menu.removeAttribute('aria-modal');
          menu.removeAttribute('aria-label');
          document.removeEventListener('keydown', trap);
        };
        const open = () => {
          menu.setAttribute('data-open', '');
          toggle.setAttribute('aria-expanded', 'true');
          menu.setAttribute('role', 'dialog');
          menu.setAttribute('aria-modal', 'true');
          menu.setAttribute('aria-label', Drupal.t('Site menu'));
          document.addEventListener('keydown', trap);
          const firstLink = menu.querySelector('a[href]');
          if (firstLink) {
            firstLink.focus();
          }
        };
        toggle.addEventListener('click', () => {
          (toggle.getAttribute('aria-expanded') === 'true' ? close : open)();
        });
        // Close after picking a destination, and on Escape.
        menu.addEventListener('click', (e) => {
          if (e.target.closest('a')) {
            close();
          }
        });
        document.addEventListener('keydown', (e) => {
          if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            close();
            toggle.focus();
          }
        });
      }

      // Front page: nav goes opaque after a short scroll (solid pages skip this).
      if (!nav.classList.contains('scrolled')) {
        const onScroll = () => {
          nav.classList.toggle('scrolled', window.scrollY > 24);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
      }
    },
  };
})(Drupal);
