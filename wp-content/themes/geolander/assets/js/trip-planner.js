/* All advice and all prices are server-rendered. Enhancement creates no crawlable URL variants. */
(() => {
  const root = document.querySelector('[data-trip-planner]');
  if (!root) return;
  const form = root.querySelector('[data-trip-form]');
  const result = root.querySelector('[data-trip-result]');
  const feedback = root.querySelector('[data-trip-feedback]');
  const fallback = root.querySelector('[data-trip-copy-fallback]');
  let brief = '';
  let revision = 0;

  form.addEventListener('change', () => {
    revision++;
    brief = '';
    result.hidden = true;
    feedback.textContent = '';
    fallback.hidden = true;
  });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    revision++;
    const pickup = form.elements.pickup;
    const dropoff = form.elements.return;
    const route = form.elements.route.value;
    const fee = Array.from(root.querySelectorAll('[data-trip-fee]'))
      .find(cell => cell.dataset.tripFee === `${pickup.value}:${dropoff.value}`);
    const advice = root.querySelector(`#trip-${route}`);
    if (!fee || !advice) return;

    const pair = `${pickup.selectedOptions[0].textContent} → ${dropoff.selectedOptions[0].textContent}`;
    result.querySelector('[data-trip-pair]').textContent = pair;
    result.querySelector('[data-trip-total]').textContent = fee.textContent;
    const link = result.querySelector('[data-trip-route-link]');
    link.textContent = advice.querySelector('h2').textContent;
    link.href = `#trip-${route}`;
    feedback.textContent = '';
    fallback.hidden = true;
    const checklist = Array.from(root.querySelectorAll('[data-trip-checklist] li'))
      .map(item => `• ${item.textContent}`).join('\n');
    const canonical = document.querySelector('link[rel="canonical"]')?.href || location.href.split(/[?#]/)[0];
    brief = [
      document.querySelector('h1').textContent, pair,
      result.querySelector('[data-trip-total]').parentElement.textContent,
      result.querySelector('[data-trip-total]').parentElement.nextElementSibling.textContent,
      link.textContent, advice.querySelector('[data-trip-advice]').textContent,
      checklist, `${canonical}#trip-${route}`,
    ].join('\n\n');
    result.hidden = false;
    result.focus();
  });

  root.querySelector('[data-trip-copy]').addEventListener('click', async () => {
    if (!brief) return;
    const currentRevision = revision;
    try {
      await navigator.clipboard.writeText(brief);
      if (currentRevision === revision) feedback.textContent = feedback.dataset.copied;
    } catch {
      if (currentRevision !== revision) return;
      fallback.hidden = false;
      const textarea = fallback.querySelector('textarea');
      textarea.value = brief;
      textarea.focus();
      textarea.select();
    }
  });
  // Without JS the roadbook, anchor navigation and full cost table remain usable.
  form.hidden = false;
})();
