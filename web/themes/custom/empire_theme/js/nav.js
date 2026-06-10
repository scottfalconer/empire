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
        const close = () => {
          menu.removeAttribute('data-open');
          toggle.setAttribute('aria-expanded', 'false');
        };
        const open = () => {
          menu.setAttribute('data-open', '');
          toggle.setAttribute('aria-expanded', 'true');
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
