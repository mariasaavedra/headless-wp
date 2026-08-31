import {
  getProgramReportCsv,
  WordPressApiError,
  WordPressAuthError,
} from "@/lib/wordpress";

/**
 * Quotes one CSV field.
 *
 * The values arrive already neutralised against spreadsheet formulas — the
 * plugin's `pcle_csv_safe()` prefixes anything starting `=`, `+`, `-` or `@`,
 * so a participant named `=HYPERLINK(...)` cannot execute on the machine of
 * whoever opens the file. What is left to do here is the quoting the format
 * itself requires.
 */
function field(value: string): string {
  return /[",\n\r]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
}

/**
 * Streams the cohort report as a file.
 *
 * A route handler rather than a server action, because this is the one thing
 * in the app whose response is a download rather than a page. The columns and
 * their order are decided in the plugin and only joined here.
 */
export async function GET(
  _request: Request,
  { params }: { params: Promise<{ id: string }> }
) {
  const { id } = await params;

  try {
    const { filename, rows } = await getProgramReportCsv(Number(id));

    // CRLF, which is what the format specifies and what Excel expects.
    const body = rows.map((row) => row.map(field).join(",")).join("\r\n");

    return new Response(body, {
      headers: {
        "Content-Type": "text/csv; charset=utf-8",
        "Content-Disposition": `attachment; filename="${filename}"`,
        "Cache-Control": "no-store",
      },
    });
  } catch (error) {
    /*
     * WordPress decides who may export; this only reports its answer honestly
     * rather than handing back an empty file that looks like a cohort of
     * nobody.
     *
     * No cookie at all raises WordPressAuthError rather than an API error —
     * it never reaches WordPress to be refused. Answering that with a 500
     * would call "you are not signed in" a server fault.
     */
    if (error instanceof WordPressAuthError) {
      return new Response("You must be signed in to export this report.", {
        status: 401,
      });
    }

    const status = error instanceof WordPressApiError ? error.status : 500;

    return new Response("This report could not be exported.", {
      status: [401, 403, 404].includes(status) ? status : 500,
    });
  }
}
