/**
 * @file
 * Records an article view via an asynchronous beacon.
 *
 * Runs on every full-article page load, including pages served from Drupal's
 * page cache. A fresh CSRF token is fetched per request (the token cannot be
 * embedded in cached HTML) and sent with the POST to the tracking endpoint.
 */

((Drupal, drupalSettings, once) => {
  Drupal.behaviors.signalViewTracker = {
    attach(context) {
      // Fire at most once per page load.
      if (!once('signal-view-tracker', 'html', context).length) {
        return;
      }

      const settings = drupalSettings.signalRecommendations || {};
      const nid = settings.nid;
      if (!nid) {
        return;
      }

      const base = drupalSettings.path.baseUrl || '/';
      const trackUrl = `${base}signal-recommendations/track/${nid}`;

      fetch(`${base}session/token`)
        .then((response) => response.text())
        .then((token) =>
          fetch(trackUrl, {
            method: 'POST',
            headers: { 'X-CSRF-Token': token },
          }),
        )
        // A failed view count must never disrupt the page.
        .catch(() => {});
    },
  };
})(Drupal, drupalSettings, once);
