/**
 * Previewing a programme the way a participant meets it.
 *
 * Staff see a programme shaped by their own records: a quiz they passed
 * while writing it leaves the module it gates unlocked, and a module they
 * ticked to check the control shows as done. Preview asks the plugin to
 * answer as a reader with nothing recorded against them, so an author can
 * see the first morning rather than their own.
 *
 * It travels in the URL rather than in a cookie: a mode you can be in
 * without seeing it is a mode you will forget you are in, and the banner
 * that says so should not be able to disagree with the address bar.
 */
const PREVIEW_PARAM = "preview";

/** Whether this request asked for the participant's view. */
function isPreview(searchParams: Record<string, string | string[] | undefined>) {
  const value = searchParams[PREVIEW_PARAM];

  return value === "1" || value === "true" || value === "";
}

/**
 * The same link, still in preview.
 *
 * Every link a reader can follow out of a previewed screen has to carry it,
 * or the preview ends silently one click in and they are looking at their
 * own progress again without being told.
 */
function keepPreview(href: string, preview: boolean): string {
  if (!preview) {
    return href;
  }

  return `${href}${href.includes("?") ? "&" : "?"}${PREVIEW_PARAM}=1`;
}

export { isPreview, keepPreview, PREVIEW_PARAM };
