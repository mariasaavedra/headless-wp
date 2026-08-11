/**
 * Renders HTML produced by WordPress.
 *
 * The markup comes from `the_content` — the same pipeline that renders the
 * WordPress-served site — so it is authored content from instructors and
 * administrators, not reader input. It is inserted as HTML on purpose:
 * escaping it would show readers their own markup as text.
 *
 * Note this is also how model answers arrive. The plugin decides server-side
 * whether to include them; if the reader is not entitled to one, it is not in
 * this string at all.
 */
export default function WpContent({
  html,
  className = "",
}: {
  html: string;
  className?: string;
}) {
  if (!html.trim()) {
    return null;
  }

  return (
    <div
      className={`wp-content ${className}`.trim()}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}
