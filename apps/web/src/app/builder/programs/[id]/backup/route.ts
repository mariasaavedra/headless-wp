import {
  exportProgram,
  WordPressApiError,
  WordPressAuthError,
} from "@/lib/wordpress";

/**
 * A name for the file that says which programme and when, so a folder of
 * backups can be read without opening each one.
 */
function filename(backup: unknown): string {
  const title =
    (backup as { programme?: { title?: unknown } })?.programme?.title;

  const slug =
    (typeof title === "string" ? title : "programme")
      .normalize("NFKD")
      .replace(/[̀-ͯ]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
      .slice(0, 60) || "programme";

  return `${slug}-backup-${new Date().toISOString().slice(0, 10)}.json`;
}

/**
 * Downloads a programme as a backup file.
 *
 * A route handler, like the report's CSV, because the answer is a download
 * rather than a page. The file is the plugin's export exactly as it came —
 * the app never reshapes it, so the version the file declares is the version
 * of what is in it.
 */
export async function GET(
  _request: Request,
  { params }: { params: Promise<{ id: string }> }
) {
  const { id } = await params;

  try {
    const backup = await exportProgram(Number(id));

    return new Response(JSON.stringify(backup, null, 2), {
      headers: {
        "Content-Type": "application/json; charset=utf-8",
        "Content-Disposition": `attachment; filename="${filename(backup)}"`,
        "Cache-Control": "no-store",
      },
    });
  } catch (error) {
    if (error instanceof WordPressAuthError) {
      return new Response("You must be signed in to back up a programme.", {
        status: 401,
      });
    }

    const status = error instanceof WordPressApiError ? error.status : 500;

    return new Response("This programme could not be backed up.", {
      status: [401, 403, 404].includes(status) ? status : 500,
    });
  }
}
