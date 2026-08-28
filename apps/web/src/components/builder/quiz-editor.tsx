import { Badge } from "@pcle/ui/components/badge";
import { Button } from "@pcle/ui/components/button";
import { Card, CardContent } from "@pcle/ui/components/card";
import { Input } from "@pcle/ui/components/input";
import { Label } from "@pcle/ui/components/label";
import { Textarea } from "@pcle/ui/components/textarea";

import ActionForm from "@/components/builder/action-form";
import { saveQuizAction } from "@/app/actions/authoring";
import { decodeEntities } from "@/lib/html";
import type { NodeDetail, QuizQuestion } from "@/lib/types";

/**
 * Shared styling for the native controls.
 *
 * `select` and `checkbox` are deliberately not the shadcn components. Both of
 * those manage state in the browser, and this whole editor is built to submit
 * without any: a native control is what guarantees the value reaches the server
 * in the form post rather than depending on JavaScript having run.
 */
const SELECT_CLASS =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const CHECKBOX_CLASS = "size-4 rounded border-input accent-primary";

/** A submit button that tells the action what to do besides saving. */
function IntentButton({
  intent,
  children,
  variant = "outline",
}: {
  intent: string;
  children: React.ReactNode;
  variant?: "outline" | "ghost" | "default";
}) {
  return (
    <Button type="submit" name="intent" value={intent} size="sm" variant={variant}>
      {children}
    </Button>
  );
}

function QuestionCard({
  question,
  index,
}: {
  question: QuizQuestion;
  index: number;
}) {
  const scored = question.type !== "text";

  return (
    <Card className="p-5">
      <CardContent className="space-y-4 p-0">
        <div className="flex items-center justify-between gap-3">
          <Badge variant="secondary">Question {index + 1}</Badge>

          {/*
            Empty for a question that has never been saved. The server mints a
            key on first save and it travels here from then on, so an answer
            already recorded against it keeps meaning the same question.
          */}
          <input type="hidden" name={`q.${index}.key`} value={question.key} />

          <IntentButton intent={`remove_question.${index}`} variant="ghost">
            Remove question
          </IntentButton>
        </div>

        <div>
          <Label htmlFor={`q-${index}-prompt`}>Question</Label>
          <Textarea
            id={`q-${index}-prompt`}
            name={`q.${index}.prompt`}
            defaultValue={question.prompt}
            rows={2}
            className="mt-1"
          />
        </div>

        <div className="flex flex-wrap items-end gap-4">
          <div>
            <Label htmlFor={`q-${index}-type`}>Answered by</Label>
            <select
              id={`q-${index}-type`}
              name={`q.${index}.type`}
              defaultValue={question.type}
              className={`mt-1 block ${SELECT_CLASS}`}
            >
              <option value="single">Choosing one answer</option>
              <option value="multiple">Choosing several answers</option>
              <option value="text">Writing freely (not scored)</option>
            </select>
          </div>

          <label className="flex items-center gap-2 pb-2 text-sm text-zinc-700">
            <input
              type="checkbox"
              name={`q.${index}.required`}
              defaultChecked={question.required}
              className={CHECKBOX_CLASS}
            />
            Must be answered
          </label>
        </div>

        <div>
          <Label htmlFor={`q-${index}-help`}>Hint (optional)</Label>
          <Input
            id={`q-${index}-help`}
            name={`q.${index}.help`}
            defaultValue={question.help}
            className="mt-1"
          />
        </div>

        {scored ? (
          <fieldset className="rounded-lg border border-zinc-200 p-4">
            <legend className="px-1 text-sm font-medium text-zinc-700">
              Answers
            </legend>

            <p className="text-xs text-zinc-500">
              Tick every answer that is correct. A question set to one answer
              keeps the first one ticked.
            </p>

            <ul className="mt-3 space-y-2">
              {question.choices.map((choice, choiceIndex) => (
                <li key={choiceIndex} className="flex items-center gap-2">
                  <input
                    type="hidden"
                    name={`q.${index}.c.${choiceIndex}.key`}
                    value={choice.key}
                  />

                  <input
                    type="checkbox"
                    name={`q.${index}.c.${choiceIndex}.correct`}
                    defaultChecked={choice.correct}
                    aria-label={`Answer ${choiceIndex + 1} is correct`}
                    className={CHECKBOX_CLASS}
                  />

                  <Input
                    name={`q.${index}.c.${choiceIndex}.text`}
                    defaultValue={choice.text}
                    aria-label={`Answer ${choiceIndex + 1}`}
                    className="flex-1"
                  />

                  <IntentButton
                    intent={`remove_choice.${index}.${choiceIndex}`}
                    variant="ghost"
                  >
                    Remove
                  </IntentButton>
                </li>
              ))}
            </ul>

            <div className="mt-3">
              <IntentButton intent={`add_choice.${index}`}>
                Add an answer
              </IntentButton>
            </div>
          </fieldset>
        ) : (
          <p className="rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-500">
            Free-text answers are recorded and shown to instructors, but they
            are not marked and do not count towards the score.
          </p>
        )}

        <div>
          <Label htmlFor={`q-${index}-feedback`}>
            What to show after answering (optional)
          </Label>
          <Textarea
            id={`q-${index}-feedback`}
            name={`q.${index}.feedback`}
            defaultValue={question.feedback}
            rows={2}
            className="mt-1"
          />
          <p className="mt-1 text-xs text-zinc-500">
            Only ever sent once the participant has answered — it usually gives
            the answer away.
          </p>
        </div>
      </CardContent>
    </Card>
  );
}

/**
 * The quiz editor.
 *
 * One form for the whole quiz. Adding a question, removing an answer and
 * saving are all the same submission: the form carries every field, the button
 * carries the intent, and the server writes the result back. That is what lets
 * this hold no client state and still work before JavaScript loads — the same
 * bargain the rest of the builder makes.
 *
 * None of the rules about what makes a valid question are restated here. The
 * plugin's sanitiser drops a question with no text, refuses a choice question
 * with fewer than two answers, and reduces "one correct answer" to one. The
 * editor's job is to show what came back from that, so an author sees what was
 * actually kept rather than what they typed.
 */
export default function QuizEditor({ node }: { node: NodeDetail }) {
  const questions = node.questions ?? [];

  return (
    <ActionForm action={saveQuizAction} className="mt-8 space-y-6">
      <input type="hidden" name="id" value={node.id} />

      <Card className="p-6">
        <CardContent className="space-y-4 p-0">
          <div>
            <Label htmlFor="quiz-title">Title</Label>
            <Input
              id="quiz-title"
              name="title"
              defaultValue={decodeEntities(node.title)}
              className="mt-1 w-full"
            />
          </div>

          <div className="flex flex-wrap items-end gap-6">
            <div>
              <Label htmlFor="quiz-pass-mark">Pass mark</Label>
              <div className="mt-1 flex items-center gap-2">
                <Input
                  id="quiz-pass-mark"
                  type="number"
                  name="pass_mark"
                  min={1}
                  max={100}
                  defaultValue={node.pass_mark ?? 70}
                  className="w-24"
                />
                <span className="text-sm text-zinc-500">
                  % of {node.max_score ?? 0} scored{" "}
                  {node.max_score === 1 ? "question" : "questions"}
                </span>
              </div>
            </div>

            <label className="flex items-center gap-2 pb-2 text-sm text-zinc-700">
              <input
                type="checkbox"
                name="gates_completion"
                defaultChecked={node.gates_completion ?? false}
                className={CHECKBOX_CLASS}
              />
              Passing is required to complete the module
            </label>
          </div>

          {/*
            Said plainly rather than left for someone to discover: the switch
            above is stored but nothing acts on it yet. An author who ticks it
            and assumes participants are being held back would be wrong, and
            that is exactly the kind of thing a compliance record cannot afford.
          */}
          <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
            Marking and scoring are not built yet, so this setting is saved but
            not yet enforced. Participants cannot take this quiz until they are.
          </p>
        </CardContent>
      </Card>

      {questions.length === 0 ? (
        <p className="text-zinc-600">
          This quiz has no questions yet.
        </p>
      ) : (
        <div className="space-y-4">
          {questions.map((question, index) => (
            <QuestionCard key={index} question={question} index={index} />
          ))}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <IntentButton intent="add_question">Add a question</IntentButton>

        <Button type="submit" name="intent" value="save" className="ml-auto">
          Save quiz
        </Button>
      </div>
    </ActionForm>
  );
}
