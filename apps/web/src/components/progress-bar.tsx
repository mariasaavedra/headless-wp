import type { Progress } from "@/lib/types";

type ProgressBarProps = {
  progress: Progress;
  label?: string;
};

/**
 * A progress bar with its numbers stated in text as well as in the fill —
 * the width alone is not information a screen reader can read out.
 */
export default function ProgressBar({ progress, label }: ProgressBarProps) {
  const { completed, total, percentage } = progress;
  const description =
    total === 0
      ? "No modules yet"
      : `${completed} of ${total} modules · ${percentage}%`;

  return (
    <div>
      <div className="flex items-baseline justify-between gap-4 text-sm">
        <span className="font-medium text-zinc-700">{label ?? "Progress"}</span>
        <span className="text-zinc-500">{description}</span>
      </div>

      <div
        className="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-200"
        role="progressbar"
        aria-valuenow={percentage}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label ? `${label}: ${description}` : description}
      >
        <div
          className="h-full rounded-full bg-emerald-600 transition-[width]"
          style={{ width: `${percentage}%` }}
        />
      </div>
    </div>
  );
}
