/**
 * @file
 * Muted autoplay preview for the featured hero (theme setting
 * `empire_hero_motion`, default "autoplay").
 *
 * CONSENT-GATED: the muted, looping, chrome-less youtube-nocookie
 * embed only mounts AFTER the visitor has consented to YouTube via Klaro — it
 * never loads external content before consent. The hero SDC stays a still
 * poster until then. Skipped entirely under prefers-reduced-motion. If the embed
 * is blocked / autoplay denied / not yet consented, the poster simply remains —
 * no UI depends on the video starting. The embed is removed (stopping playback)
 * whenever the hero scrolls out of view.
 */
((Drupal, once) => {
  // True only when the visitor has actively consented to YouTube. Fails safe:
  // any uncertainty (Klaro not ready / API change / error) → treated as NO
  // consent, so external content is never loaded before consent.
  const youtubeConsented = () => {
    try {
      const m =
        window.klaro && typeof window.klaro.getManager === 'function'
          ? window.klaro.getManager()
          : null;
      return !!(m && typeof m.getConsent === 'function' && m.getConsent('youtube'));
    } catch (e) {
      return false;
    }
  };

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
        let visible = false;

        const mountEmbed = () => {
          mountTimer = null;
          // §19: never mount without confirmed YouTube consent.
          if (slot.querySelector('iframe') || !youtubeConsented()) {
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

        // Arm the preview only while the hero is on screen AND YouTube is
        // consented; the poster gets ~2.4s first.
        const maybeArm = () => {
          if (!visible || slot.querySelector('iframe') || mountTimer !== null) {
            return;
          }
          if (youtubeConsented()) {
            mountTimer = window.setTimeout(mountEmbed, 2400);
          }
        };

        const observer = new IntersectionObserver(
          (entries) => {
            entries.forEach((entry) => {
              visible = entry.isIntersecting;
              if (visible) {
                maybeArm();
              } else {
                unmountEmbed();
              }
            });
          },
          { threshold: 0.4 },
        );
        observer.observe(hero);

        // Mount as soon as the visitor consents to YouTube while the hero shows.
        try {
          const m =
            window.klaro && typeof window.klaro.getManager === 'function'
              ? window.klaro.getManager()
              : null;
          if (m && typeof m.watch === 'function') {
            m.watch({ update: () => maybeArm() });
          }
        } catch (e) {
          // No Klaro → stays a poster, which is the safe default.
        }
      });
    },
  };
})(Drupal, once);
