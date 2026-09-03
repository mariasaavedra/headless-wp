/**
 * Shapes returned by the Platform CLE REST API (`platform-cle/v1`).
 *
 * These mirror the shaping helpers in the plugin's `includes/rest.php`. The
 * `content` fields carry HTML rendered by WordPress, authored by instructors
 * and administrators in wp-admin.
 */

type Progress = {
  completed: number;
  total: number;
  percentage: number;
};

/** A bare pointer to another post, used for breadcrumbs. */
type Ref = {
  id: number;
  title: string;
};

type ModuleSummary = {
  id: number;
  title: string;
  excerpt: string;
  completed: boolean;
};

type SessionEvent = {
  id: number;
  title: string;
  /** ISO 8601, empty when the event has no date set. */
  starts_at: string;
  /** Pre-formatted in the site's own locale and timezone. */
  formatted: string;
};

type Unit = {
  id: number;
  title: string;
  excerpt: string;
  progress: Progress;
  modules: ModuleSummary[];
  events: SessionEvent[];
};

type UnitDetail = Unit & {
  content: string;
  program: Ref | null;
};

type Program = {
  id: number;
  title: string;
  content: string;
  progress: Progress;
  units: Unit[];
};

/** A practice scenario or a template hanging off a module. */
type ModuleResource = {
  id: number;
  title: string;
  content: string;
};

type ModuleDetail = {
  id: number;
  title: string;
  content: string;
  completed: boolean;
  unit: Ref | null;
  program: Ref | null;
  scenarios: ModuleResource[];
  templates: ModuleResource[];
  quizzes: QuizSummary[];
};

/* ------------------------------------------------------------------ */
/* Quizzes, as a participant sees them                                 */
/* ------------------------------------------------------------------ */

/**
 * A choice on a quiz the reader is about to sit.
 *
 * Note what is missing: whether it is the right one. The endpoint behind these
 * types strips that before the response leaves WordPress, so there is nothing
 * for the browser to reveal — see `pcle_quiz_questions_for_taking()`.
 */
type QuizChoicePublic = {
  key: string;
  text: string;
};

type QuizQuestionPublic = {
  key: string;
  type: QuizQuestionType;
  prompt: string;
  help: string;
  required: boolean;
  choices: QuizChoicePublic[];
};

/** One past sitting, as listed on the quiz page. */
type QuizAttempt = {
  id: number;
  submitted_at: string;
  score: number;
  max_score: number;
  passed: boolean;
};

type QuizForTaking = {
  id: number;
  title: string;
  content: string;
  questions: QuizQuestionPublic[];
  pass_mark: number;
  /** Whether passing is required to complete the parent module. */
  required: boolean;
  /** Whether this reader has ever passed it. */
  passed: boolean;
  attempts: QuizAttempt[];
  module: Ref | null;
  program: Ref | null;
};

/**
 * How one question was marked.
 *
 * This is the *only* shape carrying `feedback` and `correct_keys`, and it only
 * exists in the response to a submission — answering is what unlocks it.
 */
type QuizQuestionResult = {
  key: string;
  type: QuizQuestionType;
  prompt: string;
  answered: boolean;
  feedback: string;
  scored: boolean;
  /** Scored questions only. */
  correct?: boolean;
  chosen?: string[];
  correct_keys?: string[];
  /** Free-text questions only. */
  response?: string;
};

type QuizResult = {
  attempt_id: number;
  score: number;
  max_score: number;
  percent: number;
  passed: boolean;
  questions: QuizQuestionResult[];
  module: {
    id: number;
    /** Quiz ids still standing between the reader and completing the module. */
    blockers: number[];
    completed: boolean;
  };
};

/** A quiz as it appears in its module's listing. */
type QuizSummary = {
  id: number;
  title: string;
  questions: number;
  required: boolean;
  passed: boolean;
};

/* ------------------------------------------------------------------ */
/* Cohort reports                                                      */
/* ------------------------------------------------------------------ */

/**
 * One participant's record in a cohort report.
 *
 * Deliberately raw. `undated` counts completions with no recorded date, and
 * `required_outstanding` counts required quizzes still unpassed — both are
 * reported rather than folded into a summary, because a compliance record that
 * quietly rounds them off overstates what is actually known.
 */
type ReportParticipant = {
  id: number;
  name: string;
  email: string;
  enrolled_at: string | null;
  completed: number;
  total: number;
  percent: number;
  finished: boolean;
  completed_at: string | null;
  undated: number;
  attended: number;
  sessions: number;
  quizzes_passed: number;
  quizzes: number;
  required_outstanding: number;
};

type ProgramReport = {
  program: Ref | null;
  credits: CreditHours[];
  participants: ReportParticipant[];
};

/** The export, as the plugin decided its columns. */
type ReportCsv = {
  filename: string;
  rows: string[][];
};

/** An entry of the signed-in user's programme list. */
type TrainingProgram = {
  id: number;
  title: string;
  progress: Progress;
};

export type {
  Progress,
  Ref,
  ReportParticipant,
  ProgramReport,
  ReportCsv,
  QuizChoicePublic,
  QuizQuestionPublic,
  QuizAttempt,
  QuizForTaking,
  QuizQuestionResult,
  QuizResult,
  QuizSummary,
  ModuleSummary,
  SessionEvent,
  Unit,
  UnitDetail,
  Program,
  ModuleResource,
  ModuleDetail,
  TrainingProgram,
};

/* ------------------------------------------------------------------ */
/* Authoring                                                           */
/* ------------------------------------------------------------------ */

/** Who is signed in, and what the app should offer them. */
type Me = {
  id: number;
  display_name: string;
  roles: string[];
  can_author: boolean;
  is_admin: boolean;
};

/** The curriculum post types the builder manages. */
type NodeType =
  | "pcle_program"
  | "pcle_unit"
  | "pcle_module"
  | "pcle_scenario"
  | "pcle_quiz"
  | "pcle_template"
  | "pcle_event";

/** How a quiz question is answered, and whether the server can mark it. */
type QuizQuestionType = "single" | "multiple" | "text";

/**
 * One option of a choice question.
 *
 * `correct` is present only in the authoring shape. The endpoint a participant
 * reads strips it, along with the per-question feedback — see
 * `pcle_quiz_questions_for_taking()` in the plugin.
 */
type QuizChoice = {
  key: string;
  text: string;
  correct: boolean;
};

/** One question, as its author sees it. */
type QuizQuestion = {
  key: string;
  type: QuizQuestionType;
  prompt: string;
  help: string;
  /** Shown after answering, so it often gives the answer away. */
  feedback: string;
  required: boolean;
  choices: QuizChoice[];
};

/** A programme as it appears in the builder's list. */
type AuthoringProgram = {
  id: number;
  title: string;
  status: string;
  credits: CreditHours[];
  units: number;
  modules: number;
  enrollees: number;
};

type CreditHours = {
  jurisdiction: string;
  label: string;
  hours: number;
};

/**
 * One node of the curriculum tree.
 *
 * `allowed_children` comes from the server rather than being restated here,
 * so the add menu and any future drop rules cannot drift from what the API
 * will actually accept.
 */
type TreeNode = {
  id: number;
  type: NodeType;
  title: string;
  status: string;
  menu_order: number;
  allowed_children: NodeType[];
  children: TreeNode[];
  /** Events only. */
  starts_at?: string;
  /** Scenarios only. */
  has_model_answer?: boolean;
  /** Quizzes only: how many questions have been written. */
  questions?: number;
  /** Quizzes only: whether passing is required to complete the module. */
  gates_completion?: boolean;
  /** Programmes only. */
  credits?: CreditHours[];
};

/** A file attached to a node through the builder. */
type UploadedMedia = {
  id: number;
  /** What to drop into the body — `[[media:12]]`. */
  token: string;
  filename: string;
  title: string;
  mime: string;
  is_image: boolean;
  url: string;
  /** True when it lives behind the per-programme download gate. */
  protected: boolean;
};

/** A region of the body the authored syntax cannot spell. */
type PreservedRegion = {
  /** The token standing in for it in `body`. */
  token: string;
  /** What it is, for the note beside the editor — "Table", "Image gallery". */
  label: string;
};

/**
 * One node opened for editing.
 *
 * `body` is the authored text, not HTML — the server turns it into block
 * markup. Anything the syntax cannot spell appears in it as a token, listed in
 * `preserved`: the author can move or delete one, and its content is copied
 * back from the stored post on save rather than round-tripped through here.
 *
 * `editable` is always true and kept only so older callers keep working.
 */
type NodeDetail = Omit<TreeNode, "questions"> & {
  body: string;
  editable: boolean;
  preserved: PreservedRegion[];
  excerpt: string;
  rendered: string;
  parent: Ref | null;
  program: Ref | null;
  /**
   * Quizzes only. The tree carries a count; opening the node carries the
   * questions themselves, answers included, because this route is staff-only.
   */
  questions?: QuizQuestion[];
  pass_mark?: number;
  max_score?: number;
};

export type {
  Me,
  NodeType,
  QuizQuestionType,
  QuizChoice,
  QuizQuestion,
  AuthoringProgram,
  CreditHours,
  TreeNode,
  NodeDetail,
  PreservedRegion,
  UploadedMedia,
};
