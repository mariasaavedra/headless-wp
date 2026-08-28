import { redirect } from "next/navigation";

import { Badge } from "@pcle/ui/components/badge";
import { Card, CardContent } from "@pcle/ui/components/card";

import { renderAccessError } from "@/components/access-error";
import Breadcrumbs from "@/components/breadcrumbs";
import PageShell from "@/components/page-shell";
import QuizRunner from "@/components/quiz-runner";
import WpContent from "@/components/wp-content";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import type { QuizAttempt, QuizForTaking } from "@/lib/types";
import { getQuiz } from "@/lib/wordpress";

/**
 * Past sittings.
 *
 * Shown in full rather than reduced to a best score: every attempt is kept on
 * the server, and a participant should be able to see the same history a
 * cohort report would.
 */
function Attempts({ attempts }: { attempts: QuizAttempt[] }) {
  if (attempts.length === 0) {
    return null;
  }

  return (
    <section className="mt-10">
      <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-400">
        Your attempts
      </h2>

      <ul className="mt-3 space-y-2">
        {attempts.map((attempt) => (
          <li
            key={attempt.id}
            className="flex flex-wrap items-center gap-3 border-b border-zinc-100 pb-2 text-sm"
          >
            <Badge
              className={
                attempt.passed
                  ? "bg-emerald-100 text-emerald-800"
                  : "bg-zinc-100 text-zinc-700"
              }
            >
              {attempt.passed ? "Passed" : "Not passed"}
            </Badge>

            <span className="text-zinc-900">
              {attempt.score} / {attempt.max_score}
            </span>

            <span className="text-zinc-500">
              {attempt.submitted_at
                ? attempt.submitted_at.slice(0, 16).replace("T", " ")
                : "date not recorded"}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

export default async function QuizPage({ params }: PageProps<"/quizzes/[id]">) {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  const { id } = await params;

  let quiz: QuizForTaking;

  try {
    quiz = await getQuiz(Number(id));
  } catch (error) {
    return renderAccessError(error);
  }

  return (
    <PageShell>
      <Breadcrumbs
        trail={[
          { label: "My Training", href: "/my-training" },
          ...(quiz.program
            ? [{ label: quiz.program.title, href: `/programs/${quiz.program.id}` }]
            : []),
          ...(quiz.module
            ? [{ label: quiz.module.title, href: `/modules/${quiz.module.id}` }]
            : []),
          { label: quiz.title },
        ]}
      />

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
          {decodeEntities(quiz.title)}
        </h1>

        {quiz.passed && (
          <Badge className="bg-emerald-100 text-emerald-800">Passed</Badge>
        )}

        {quiz.required && !quiz.passed && (
          <Badge className="bg-amber-100 text-amber-800">
            Required for this module
          </Badge>
        )}
      </div>

      <p className="mt-2 text-sm text-zinc-500">
        {quiz.questions.length}{" "}
        {quiz.questions.length === 1 ? "question" : "questions"} · pass mark{" "}
        {quiz.pass_mark}%
      </p>

      {quiz.content.trim() && <WpContent html={quiz.content} className="mt-6" />}

      <div className="mt-8">
        {quiz.questions.length === 0 ? (
          <Card className="p-6">
            <CardContent className="p-0">
              <p className="text-zinc-600">
                This quiz has no questions yet. Your instructor is still
                writing it.
              </p>
            </CardContent>
          </Card>
        ) : (
          <QuizRunner quiz={quiz} />
        )}
      </div>

      <Attempts attempts={quiz.attempts} />
    </PageShell>
  );
}
