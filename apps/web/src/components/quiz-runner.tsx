"use client";

import { useActionState } from "react";

import { Badge } from "@pcle/ui/components/badge";
import { Button } from "@pcle/ui/components/button";
import { Card, CardContent } from "@pcle/ui/components/card";
import {
  Questionnaire,
  QuestionnaireActions,
  QuestionnaireChoice,
  QuestionnaireChoices,
  QuestionnaireDescription,
  QuestionnaireInput,
  QuestionnaireItem,
  QuestionnaireNext,
  QuestionnairePrevious,
  QuestionnaireProgress,
  QuestionnaireSubmit,
  QuestionnaireTitle,
} from "@pcle/ui/components/questionnaire";

import { submitQuizAction } from "@/app/actions/quiz";
import { decodeEntities } from "@/lib/html";
import type { QuizForTaking, QuizResult } from "@/lib/types";

/**
 * What the reader sees once the server has marked their answers.
 *
 * Everything here — whether a question was right, which choices were, the
 * explanation — arrives in the submission response and exists nowhere else in
 * the client. Answering is what unlocks it.
 */
function Results({
  quiz,
  result,
  onRetake,
}: {
  quiz: QuizForTaking;
  result: QuizResult;
  onRetake: () => void;
}) {
  const choiceText = (questionKey: string, choiceKey: string) =>
    quiz.questions
      .find((question) => question.key === questionKey)
      ?.choices.find((choice) => choice.key === choiceKey)?.text ?? choiceKey;

  return (
    <div className="space-y-6">
      <Card
        className={
          result.passed
            ? "border-emerald-300 p-6 ring-emerald-500/30"
            : "border-amber-300 p-6 ring-amber-500/30"
        }
      >
        <CardContent className="p-0">
          <p className="text-sm font-medium uppercase tracking-wide text-zinc-500">
            {result.passed ? "Passed" : "Not passed"}
          </p>

          <p className="mt-2 text-3xl font-semibold text-zinc-950">
            {result.score} / {result.max_score}
            <span className="ml-3 text-lg font-normal text-zinc-500">
              {result.percent}%
            </span>
          </p>

          <p className="mt-2 text-sm text-zinc-600">
            {result.passed
              ? `The pass mark is ${quiz.pass_mark}%.`
              : `You need ${quiz.pass_mark}% to pass. You can sit it again.`}
          </p>

          {quiz.required && (
            <p className="mt-3 text-sm text-zinc-600">
              {result.module.blockers.length === 0
                ? "You can now mark this module as complete."
                : "This module cannot be marked complete until you pass."}
            </p>
          )}
        </CardContent>
      </Card>

      <ol className="space-y-4">
        {result.questions.map((question, index) => (
          <li key={question.key}>
            <Card className="p-5">
              <CardContent className="space-y-3 p-0">
                <div className="flex flex-wrap items-center gap-3">
                  <Badge variant="secondary">{index + 1}</Badge>

                  {question.scored ? (
                    <Badge
                      className={
                        question.correct
                          ? "bg-emerald-100 text-emerald-800"
                          : "bg-red-100 text-red-800"
                      }
                    >
                      {question.correct ? "Correct" : "Incorrect"}
                    </Badge>
                  ) : (
                    <Badge variant="secondary">Not scored</Badge>
                  )}
                </div>

                <p className="font-medium text-zinc-900">{question.prompt}</p>

                {question.scored ? (
                  <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt className="text-zinc-500">You chose</dt>
                    <dd className="text-zinc-900">
                      {question.chosen?.length
                        ? question.chosen
                            .map((key) => choiceText(question.key, key))
                            .join(", ")
                        : "Nothing"}
                    </dd>

                    {!question.correct && (
                      <>
                        <dt className="text-zinc-500">Correct</dt>
                        <dd className="text-zinc-900">
                          {question.correct_keys
                            ?.map((key) => choiceText(question.key, key))
                            .join(", ")}
                        </dd>
                      </>
                    )}
                  </dl>
                ) : (
                  <p className="whitespace-pre-line rounded-lg bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
                    {question.response || "You left this blank."}
                  </p>
                )}

                {question.feedback && (
                  <p className="border-l-2 border-zinc-300 pl-3 text-sm text-zinc-600">
                    {question.feedback}
                  </p>
                )}
              </CardContent>
            </Card>
          </li>
        ))}
      </ol>

      <Button variant="outline" onClick={onRetake}>
        Sit it again
      </Button>
    </div>
  );
}

/**
 * The quiz itself.
 *
 * `Questionnaire` renders a real <form>, and its submit handler only cancels
 * the submission when its own validation fails — so the server action can be
 * handed straight to `action`, and a required question left blank never
 * reaches the network. The server checks that again anyway; this endpoint is
 * reachable without the form.
 *
 * Each question's field is named for its question key. The hidden `type.<key>`
 * inputs are how the action knows whether to read one value or several — a
 * fact about the question, not something to infer from what came back.
 */
export default function QuizRunner({ quiz }: { quiz: QuizForTaking }) {
  const [state, formAction, pending] = useActionState(submitQuizAction, {});

  if (state.result) {
    return (
      <Results
        quiz={quiz}
        result={state.result}
        // Re-mounting the form is the reset: a fresh sitting should not start
        // pre-filled with the answers that just failed.
        onRetake={() => window.location.reload()}
      />
    );
  }

  const items = quiz.questions.map((question) => ({
    name: question.key,
    required: question.required,
    choices:
      question.type === "text"
        ? undefined
        : question.choices.map((choice) => ({ value: choice.key })),
  }));

  return (
    <Questionnaire action={formAction} items={items} shortcuts="letters">
      <input type="hidden" name="quiz_id" value={quiz.id} />

      {quiz.questions.map((question) => (
        <input
          key={question.key}
          type="hidden"
          name={`type.${question.key}`}
          value={question.type}
        />
      ))}

      <QuestionnaireProgress />

      {quiz.questions.map((question) => (
        <QuestionnaireItem
          key={question.key}
          name={question.key}
          required={question.required}
          multiple={question.type === "multiple"}
        >
          <QuestionnaireTitle>
            {decodeEntities(question.prompt)}
          </QuestionnaireTitle>

          {question.help && (
            <QuestionnaireDescription>
              {decodeEntities(question.help)}
            </QuestionnaireDescription>
          )}

          {question.type === "text" ? (
            <QuestionnaireInput placeholder="Your answer" />
          ) : (
            <QuestionnaireChoices>
              {question.choices.map((choice) => (
                <QuestionnaireChoice key={choice.key} value={choice.key}>
                  {decodeEntities(choice.text)}
                </QuestionnaireChoice>
              ))}
            </QuestionnaireChoices>
          )}
        </QuestionnaireItem>
      ))}

      <QuestionnaireActions>
        <QuestionnairePrevious />
        <QuestionnaireNext />
        <QuestionnaireSubmit disabled={pending}>
          {pending ? "Submitting…" : "Submit"}
        </QuestionnaireSubmit>
      </QuestionnaireActions>

      {state.error && (
        <p role="alert" className="text-sm text-red-700">
          {state.error}
        </p>
      )}
    </Questionnaire>
  );
}
