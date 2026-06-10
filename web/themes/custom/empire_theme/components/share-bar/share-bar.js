/**
 * @file
 * Enhances the Empire share bar: builds share-intent URLs from the current page,
 * wires the Web Share API + copy-to-clipboard, then reveals the bar. No
 * third-party scripts; nothing is requested until the visitor clicks a link.
 */
((Drupal, once) => {
  Drupal.behaviors.empireShareBar = {
    attach(context) {
      once('empire-share-bar', '[data-empire-share]', context).forEach((bar) => {
        const url = window.location.href;
        const title = bar.getAttribute('data-share-title') || document.title;
        const enc = encodeURIComponent;

        const x = bar.querySelector('[data-share-x]');
        if (x) {
          x.href = `https://x.com/intent/tweet?url=${enc(url)}&text=${enc(title)}`;
        }
        const fb = bar.querySelector('[data-share-fb]');
        if (fb) {
          fb.href = `https://www.facebook.com/sharer/sharer.php?u=${enc(url)}`;
        }
        const wa = bar.querySelector('[data-share-wa]');
        if (wa) {
          wa.href = `https://wa.me/?text=${enc(`${title} ${url}`)}`;
        }

        const native = bar.querySelector('[data-share-native]');
        if (native && typeof navigator.share === 'function') {
          native.hidden = false;
          native.addEventListener('click', () => {
            navigator.share({ title, url }).catch(() => {});
          });
        }

        const copy = bar.querySelector('[data-share-copy]');
        if (copy && navigator.clipboard) {
          copy.addEventListener('click', () => {
            navigator.clipboard.writeText(url).then(() => {
              const original = copy.textContent;
              copy.textContent = Drupal.t('Copied!');
              window.setTimeout(() => {
                copy.textContent = original;
              }, 1500);
            }).catch(() => {});
          });
        }

        bar.hidden = false;
      });
    },
  };
})(Drupal, once);
