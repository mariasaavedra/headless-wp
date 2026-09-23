import type { Author, Authorship } from "@/lib/types";

const MONTHS = [
  "Jan", "Feb", "Mar", "Apr", "May", "Jun",
  "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
];

/**
 * "23 Sep 2026, 14:02", read straight off the ISO string.
 *
 * The server sends the site's own wall-clock time with its offset. Going
 * through `Date` would convert it to whatever zone this server runs in —
 * UTC on Vercel — and an edit made at two in the afternoon in Kansas City
 * would say seven in the evening.
 */
function formatSiteTime(iso: string | null): string | null {
  const match = iso?.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);

  if (!match) {
    return null;
  }

  const [, year, month, day, hour, minute] = match;

  return `${Number(day)} ${MONTHS[Number(month) - 1]} ${year}, ${hour}:${minute}`;
}

function name(person: Author): string {
  return person.name ?? "a former account";
}

/**
 * Who made this, and who last changed it.
 *
 * The editor is left out rather than guessed when the server does not know
 * it: content last changed before editors were recorded says only when.
 */
function authorshipText(item: Authorship): string {
  const parts: string[] = [];
  const created = formatSiteTime(item.created_at);
  const edited = formatSiteTime(item.edited_at);

  if (item.created_by) {
    parts.push(
      `Created by ${name(item.created_by)}${created ? ` on ${created}` : ""}`
    );
  }

  if (edited) {
    parts.push(
      item.edited_by
        ? `last edited by ${name(item.edited_by)} on ${edited}`
        : `last edited on ${edited}`
    );
  }

  const text = parts.join(" · ");

  return text.charAt(0).toUpperCase() + text.slice(1);
}

export default function AuthorshipLine({
  item,
  className = "mt-2 text-xs text-zinc-500",
}: {
  item: Authorship;
  className?: string;
}) {
  const text = authorshipText(item);

  return text ? <p className={className}>{text}</p> : null;
}

export { authorshipText, formatSiteTime };
