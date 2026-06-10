/**
 * @file
 * Optional muted autoplay preview for the featured hero (opt-in theme setting
 * `empire_hero_motion`). Attached only when the owner has enabled it.
 *
 * The hero SDC stays a still poster; this behaviour finds the wrapper carrying
 * the featured video's YouTube ID, injects a preview layer into the hero
 * backdrop (over the poster, under the text scrims), and after ~2.4s mounts a
 * muted, looping, chrome-less youtube-nocookie embed that fades in once loaded.
 * Skipped entirely under prefers-reduced-motion; the embed is removed (stopping
 * playback) whenever the hero scrolls out of view. If the embed never loads
 * (blocked / autoplay denied / error) the still poster simply remains — no UI
 * depends on the video starting.
 */
((Drupal, once) => {
  Drupal.behaviors.empireHeroPreview = {
    attach(context) {
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
      }
      once('empire-hero-preview', '[data-empire-hero-video]', context).forEach((mount) => {
        const id = mount.getAttribute('data-empire-hero-video');
        const hero = mount.querySelector('.empire-hero');
        const backdrop = mount.querySelector('.empire-hero__backdrop');
        if (!hero || !backdrop || !/^[A-Za-z0-9_-]{11}$/.test(id || '')) {
          return;
        }

        // Preview layer: after the poster img, before the fades (so the scrims
        // still protect the title text).
        const slot = document.createElement('div');
        slot.className = 'empire-hero__preview';
        backdrop.insertBefore(slot, backdrop.querySelector('.empire-hero__fade-l'));

        let mountTimer = null;

        const mountEmbed = () => {
          mountTimer = null;
          if (slot.querySelector('iframe')) {
            return;
          }
          const params =
            'autoplay=1&mute=1&controls=0&loop=1&playlist=' +
            id +
            '&playsinline=1&modestbranding=1&rel=0&disablekb=1&fs=0&start=4';
          const iframe = document.createElement('iframe');
          iframe.src = 'https://www.youtube-nocookie.com/embed/' + id + '?' + params;
          iframe.allow = 'autoplay; encrypted-media; picture-in-picture';
          iframe.setAttribute('tabindex', '-1');
          iframe.setAttribute('aria-hidden', 'true');
          iframe.setAttribute('title', '');
          iframe.addEventListener('load', () => slot.classList.add('is-live'));
          slot.appendChild(iframe);
        };

        const unmountEmbed = () => {
          if (mountTimer !== null) {
            window.clearTimeout(mountTimer);
            mountTimer = null;
          }
          slot.classList.remove('is-live');
          const iframe = slot.querySelector('iframe');
          if (iframe) {
            iframe.remove();
          }
        };

        // Mount only while the hero is on screen; the poster gets ~2.4s first.
        const observer = new IntersectionObserver(
          (entries) => {
            entries.forEach((entry) => {
              if (entry.isIntersecting) {
                if (!slot.querySelector('iframe') && mountTimer === null) {
                  mountTimer = window.setTimeout(mountEmbed, 2400);
                }
              } else {
                unmountEmbed();
              }
            });
          },
          { threshold: 0.4 },
        );
        observer.observe(hero);
      });
    },
  };
})(Drupal, once);
