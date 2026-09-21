/**
 * Which build of the app you are looking at.
 *
 * On every screen, because the question it answers — "is what I am seeing the
 * change we just deployed?" — is asked from whichever screen happens to be
 * open, and this repository deploys its two halves by different means and at
 * different moments.
 *
 * The commit is the part that actually identifies the code. The version is
 * slow-moving and the date is for humans; the seven characters in the middle
 * are what can be pasted into `git show`.
 */
export default function VersionStamp() {
  const version = process.env.BUILD_VERSION ?? "0.0.0";
  const commit = process.env.BUILD_COMMIT ?? "unknown";
  const built = process.env.BUILD_TIME;

  const date = built
    ? new Date(built).toLocaleDateString("en-GB", {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : null;

  return (
    <footer className="border-t border-zinc-200 bg-white">
      <div className="mx-auto max-w-6xl px-6 py-3 text-xs text-zinc-400">
        <span className="font-medium text-zinc-500">Platform CLE</span> v
        {version}
        <span aria-hidden="true"> · </span>
        <code className="font-mono">{commit}</code>
        {date && (
          <>
            <span aria-hidden="true"> · </span>
            <span>built {date}</span>
          </>
        )}
      </div>
    </footer>
  );
}
