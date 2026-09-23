import Link from "next/link";

import { Button } from "@pcle/ui/components/button";

/**
 * Says, on every previewed screen, that this is not your own view.
 *
 * Without it the two views are indistinguishable — the giveaway is progress
 * you did not expect to be zero — and an author could spend ten minutes
 * wondering why the module they finished this morning looks untouched.
 */
export default function PreviewBanner({
  programmeId,
}: {
  /** Where "Leave preview" goes: the builder, where preview started. */
  programmeId?: number;
}) {
  return (
    <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
      <p className="text-sm text-amber-900">
        <span className="font-medium">Previewing as a participant.</span>{" "}
        Nothing you have completed or passed counts here, and every quiz gate
        is closed — this is the programme as somebody meets it on their first
        morning.
      </p>

      <Button
        variant="outline"
        size="sm"
        className="ml-auto"
        nativeButton={false}
        render={
          <Link href={programmeId ? `/builder/programs/${programmeId}` : "/builder"} />
        }
      >
        Leave preview
      </Button>
    </div>
  );
}
