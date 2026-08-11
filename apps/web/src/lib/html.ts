/**
 * WordPress returns HTML-encoded text in its JSON: the site title comes back
 * as "Platform by Pen &amp; Sword", not "Platform by Pen & Sword". React then
 * escapes that string again on render, so the browser ends up displaying the
 * entity itself — "Pen &amp; Sword".
 *
 * Decoding is deliberately NOT applied inside wordpressFetch. Fields like
 * `content.rendered` are real HTML, and decoding entities there would turn
 * an escaped `&lt;script&gt;` back into a live tag — an XSS vector rather
 * than a formatting fix. Only plain-text fields that are rendered as text
 * should pass through here.
 */

const NAMED_ENTITIES: Record<string, string> = {
  amp: "&",
  lt: "<",
  gt: ">",
  quot: '"',
  apos: "'",
  nbsp: " ",
  ndash: "–",
  mdash: "—",
  lsquo: "‘",
  rsquo: "’",
  ldquo: "“",
  rdquo: "”",
  hellip: "…",
};

const ENTITY_PATTERN = /&(#\d+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/g;

/**
 * Decodes HTML entities in a plain-text string from the WordPress API.
 *
 * Runs in a single pass, so "&amp;lt;" decodes to the literal "&lt;" rather
 * than being unescaped twice into a tag.
 */
function decodeEntities(text: string): string {
  return text.replace(ENTITY_PATTERN, (match, entity: string) => {
    if (entity.startsWith("#")) {
      const isHex = entity[1] === "x" || entity[1] === "X";
      const codePoint = isHex
        ? Number.parseInt(entity.slice(2), 16)
        : Number.parseInt(entity.slice(1), 10);

      if (!Number.isFinite(codePoint) || codePoint <= 0 || codePoint > 0x10ffff) {
        return match;
      }

      return String.fromCodePoint(codePoint);
    }

    return NAMED_ENTITIES[entity] ?? match;
  });
}

export { decodeEntities };
